<?php

namespace Net\EPP;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Net\EPP\IT\Session;
use OTPHP\TOTP;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use RedBeanPHP\R;
use Slim\App;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;

/**
 * App-layer helpers that don't belong to any single EPP object: audit-trail
 * logging, EPP-session lifecycle, JWT/fixed-token/TOTP auth, Slim middleware
 * (CORS, trailing-slash normalization, error handling), CSV export,
 * client-IP/CIDR checks, and request validation. Unified here as one
 * PSR-4-autoloaded class instead of the free-function files they used to
 * live in (helpers/changelog.php, csv.php, epp.php, jwt.php, middleware.php,
 * network.php, totp.php, validate.php).
 */
final class Helpers
{
    /**
     * contacts table column widths, config/mariadb-schema.sql
     */
    public const CONTACT_FIELD_MAX_LENGTHS = [
        'handle'          => 32,
        'name'            => 256,
        'org'             => 256,
        'street'          => 256,
        'street2'         => 128,
        'street3'         => 128,
        'city'            => 128,
        'province'        => 128,
        'postalcode'      => 16,
        'countrycode'     => 2,
        'voice'           => 64,
        'fax'             => 64,
        'email'           => 64,
        'authinfo'        => 64,
        'nationalitycode' => 2,
        'regcode'         => 32,
        'schoolcode'      => 32,
    ];

    /**
     * domains table column widths, config/mariadb-schema.sql
     */
    public const DOMAIN_FIELD_MAX_LENGTHS = [
        'domain'     => 255,
        'authinfo'   => 64,
        'registrant' => 32,
        'admin'      => 32,
    ];

    /** BOM as a string for comparison. */
    public const BOM = "\xef\xbb\xbf";

    // -----------------------------------------------------------------
    // changelog
    // -----------------------------------------------------------------

    /**
     * record an audit-trail entry for a create/update/delete
     *
     * @param string $object contacts/domains/users
     * @param int $object_id the object's DB row id
     * @param string $action create/update/delete
     * @param array $data changed fields (or a minimal identifying set, for create/delete)
     * @param int|null $user_id acting user
     */
    public static function logChanges(string $object, int $object_id, string $action, array $data, ?int $user_id): void {
        R::exec("
            INSERT INTO changelog (user_id, object, object_id, action, data)
            VALUES (:user_id, :object, :object_id, :action, :data)
        ", [
            ':user_id'   => $user_id,
            ':object'    => $object,
            ':object_id' => $object_id,
            ':action'    => $action,
            ':data'      => json_encode($data),
        ]);
    }

    // -----------------------------------------------------------------
    // EPP session lifecycle
    // -----------------------------------------------------------------

    /**
     * Run $fn against a fresh, logged-in EPP session, then always log out.
     * Connect-per-request, matching every examples/*.php and CLI/*.php script's
     * own hello()/login()/logout() pattern -- only call this from handlers that
     * actually need a live registry round-trip.
     *
     * @param callable $fn function(Client $nic, Session $session)
     * @return mixed whatever $fn returns
     * @throws \RuntimeException if hello() or login() fails
     */
    public static function withEppSession(callable $fn): mixed {
        $nic = new Client();
        $session = new Session($nic);

        if ( ! $session->hello()) {
            throw new \RuntimeException('EPP session unavailable: connection failed');
        }
        if ($session->login() === FALSE) {
            throw new \RuntimeException('EPP session unavailable: login failed (' . $session->getError() . ')');
        }

        try {
            return $fn($nic, $session);
        } finally {
            $session->logout();
        }
    }

    /**
     * Act on any unacknowledged `passwdReminder` poll message by rotating the
     * shared EPP registry password.
     *
     * The registry warns, through the poll queue, that the account password is
     * approaching expiry; Session::parsePollReq() already recognises and stores
     * those messages, but nothing acted on them, so the warning just piled up
     * until the credential expired and every EPP call started failing.
     *
     * This cannot go through withEppSession(): the new password is carried by
     * the EPP <login> command itself (Session::login($newPW)), so the rotation
     * has to *be* the login, not something done inside an existing session.
     * Call it after any withEppSession() work has finished and logged out.
     *
     * At most one rotation is attempted per 24 hours, tracked by the `epp`
     * setting's `lastPasswordUpdate` (a unix timestamp). The stamp is written
     * *before* the attempt, deliberately: if a rotation half-succeeds -- the
     * registry accepts the new password but the response is lost -- retrying
     * minutes later with yet another password would make things worse, and the
     * registry re-sends its reminder well before the credential actually
     * expires, so waiting a day is safe.
     *
     * @return array human-readable log lines, in the same style as PollProcessor
     */
    public static function rotateEppPasswordOnReminder(): array {
        $log = [];

        $reminders = R::getAll(
            "SELECT id, data FROM messages WHERE type = 'passwdReminder' AND archived_time IS NULL ORDER BY id ASC"
        );
        if (empty($reminders)) {
            return ["no outstanding passwdReminder messages"];
        }
        $log[] = count($reminders) . " passwdReminder message(s) outstanding (registry reports expiry '" . $reminders[0]['data'] . "')";

        $epp = Config::get('epp');
        $last = (int) ($epp['lastPasswordUpdate'] ?? 0);
        $age = time() - $last;
        if ($last > 0 && $age < 86400) {
            $log[] = "  last rotation attempt was " . round($age / 3600, 1) . "h ago -- skipping (one attempt per 24h)";
            return $log;
        }

        // 16 hex characters: cryptographically random, and the same character
        // class the registry already accepts for this credential
        $newPassword = bin2hex(random_bytes(8));

        // mark the attempt before making it -- see the note above
        $epp['lastPasswordUpdate'] = time();
        Config::set('epp', $epp);

        $nic = new Client();
        $session = new Session($nic);

        if ( ! $session->hello()) {
            $log[] = "  FAILED: registry connection unavailable -- messages left unacknowledged, will retry after 24h";
            return $log;
        }
        if ($session->login($newPassword) === FALSE) {
            $log[] = "  FAILED: registry rejected the password change (" . $session->getError() . ")";
            $log[] = "  messages left unacknowledged, will retry after 24h";
            return $log;
        }
        $session->logout();

        // The registry has already accepted the new password at this point, so a
        // failure to persist it locally locks this installation out of EPP
        // entirely. Print the password: an operator reading the cron log is the
        // only remaining way to recover it.
        try {
            $epp['password'] = $newPassword;
            Config::set('epp', $epp);
        } catch (\Throwable $e) {
            $log[] = "  CRITICAL: the registry password was changed to '{$newPassword}' but storing it";
            $log[] = "  locally FAILED (" . $e->getMessage() . "). Set the 'epp' setting's password to that";
            $log[] = "  value by hand NOW -- EPP access is broken until you do.";
            return $log;
        }

        foreach ($reminders as $reminder) {
            R::exec("UPDATE messages SET archived_time = NOW() WHERE id = ?", [$reminder['id']]);
        }

        $log[] = "  registry password rotated and stored, " . count($reminders) . " message(s) acknowledged";
        return $log;
    }

    // -----------------------------------------------------------------
    // JWT / fixed API token auth
    // -----------------------------------------------------------------

    /**
     * check whether $token matches a stored fixed automation token (users.api_token,
     * hashed at rest) and, if so, synthesize a decoded-claims object shaped exactly
     * like what JWT::decode() would return -- so every downstream consumer
     * (jwtUserID/jwtRequireAdmin/jwtRequireMfa, and every route reading
     * $decoded->data->id / ->admin / etc.) works identically regardless of which
     * auth mechanism was actually used. Returns null if $token isn't a valid,
     * unexpired fixed token, so callers can fall through to their normal failure
     * path unchanged.
     *
     * Automation tokens bypass MFA entirely (has_totp forced false) -- there's no
     * human present to enter a TOTP code in a headless/scripted context.
     *
     * @param string $token the raw bearer token from the Authorization header
     * @return object|null synthesized decoded-claims object, or null if not a valid fixed token
     */
    private static function verifyFixedApiToken(string $token): ?object {
        $user = R::getRow("
            SELECT id, admin, username
            FROM users
            WHERE api_token = :token AND active = 1
              AND (api_token_expires = 0 OR api_token_expires > UNIX_TIMESTAMP())
        ", [':token' => hash('sha256', $token)]);

        if (empty($user)) {
            return null;
        }

        return (object) [
            'data' => (object) [
                'id'            => (int) $user['id'],
                'admin'         => (int) $user['admin'],
                'username'      => $user['username'],
                'has_totp'      => false,
                'needs_totp'    => false,
                'totp_verified' => false,
            ],
        ];
    }

    /**
     * validate the Authorization header: a real JWT (session login), falling
     * back to a fixed automation token (see verifyFixedApiToken()) if JWT
     * decoding fails for any reason
     *
     * @param Request $request the incoming HTTP request
     * @return object decoded claims, shaped identically regardless of which auth mechanism matched
     * @throws HttpUnauthorizedException if neither a valid JWT nor a valid fixed token is found
     */
    public static function jwtVerify(Request $request): object {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            throw new HttpUnauthorizedException($request, 'Authorization token not set');
        }

        $parts = explode(' ', $authHeader);
        if (count($parts) !== 2 || empty($parts[1])) {
            throw new HttpUnauthorizedException($request, 'Authorization token not valid');
        }
        $token = $parts[1];

        // a fixed automation token never round-trips through JWT::decode() successfully
        // (it's an opaque random string, not a signed header.payload.signature triple),
        // so on any decode failure below, check it against the fixed-token store before
        // giving up -- this only adds work on the failure path, never the normal one
        try {
            return JWT::decode($token, new Key(Config::get('jwt_psk'), 'HS256'));
        } catch (SignatureInvalidException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Signature is not valid: ' . $e->getMessage());
        } catch (BeforeValidException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Token is not yet valid: ' . $e->getMessage());
        } catch (ExpiredException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Token has expired: ' . $e->getMessage());
        } catch (\UnexpectedValueException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Token contains unexpected value: ' . $e->getMessage());
        } catch (\DomainException $e) {
            if ($fixed = self::verifyFixedApiToken($token)) return $fixed;
            throw new HttpUnauthorizedException($request, 'Token outside expected domain: ' . $e->getMessage());
        }
    }

    /**
     * @param Request $request the incoming HTTP request
     * @return int the authenticated user's id
     */
    public static function jwtUserID(Request $request): int {
        return (int) self::jwtVerify($request)->data->id;
    }

    /**
     * require that MFA, if enabled for this user, has already been verified this session
     *
     * @param Request $request the incoming HTTP request
     * @return object decoded JWT claims
     * @throws HttpForbiddenException if MFA is enabled but not yet verified
     */
    public static function jwtRequireMfa(Request $request): object {
        $decoded = self::jwtVerify($request);
        if (!empty($decoded->data->has_totp) && empty($decoded->data->totp_verified)) {
            throw new HttpForbiddenException($request, 'MFA verification required');
        }
        return $decoded;
    }

    /**
     * require an admin-privileged, MFA-verified (if enabled) token
     *
     * @param Request $request the incoming HTTP request
     * @return int the authenticated admin's user id
     * @throws HttpForbiddenException if not an admin, or MFA is enabled but not yet verified
     */
    public static function jwtRequireAdmin(Request $request): int {
        $decoded = self::jwtVerify($request);
        if ((int) $decoded->data->admin !== 1) {
            throw new HttpForbiddenException($request, 'Admin access required');
        }
        if (!empty($decoded->data->has_totp) && empty($decoded->data->totp_verified)) {
            throw new HttpForbiddenException($request, 'MFA verification required');
        }
        return (int) $decoded->data->id;
    }

    /**
     * sign a new JWT for $data, adding the standard claims
     *
     * The token's lifetime comes from $data['max_token_age'], in minutes --
     * spelled exactly like the users column and the claim every caller already
     * passes. It used to be read as 'maxTokenAge', which no call site ever set,
     * so users.max_token_age was silently ignored and every token got the
     * 240-minute default. A null or non-positive value still means "use the
     * default": 0 would otherwise mint a token that has already expired.
     *
     * @param array $data claims to embed (may include 'max_token_age', in minutes, defaulting to 240)
     * @return array $data merged with the signed 'token' string
     */
    public static function jwtBuild(array $data): array {
        $maxTokenAge = (int) ($data['max_token_age'] ?? 0);
        if ($maxTokenAge <= 0) {
            $maxTokenAge = 240;
        }

        $iat = time();
        $nbf = $iat - 60 * 5;
        $exp = $iat + 60 * $maxTokenAge;

        $data['exp'] = $exp;

        $payload = [
            'data' => $data,
            'iss'  => 'http://www.inet-services.it',
            'sub'  => 'invoicing API',
            'nbf'  => $nbf,
            'iat'  => $iat,
            'exp'  => $exp,
        ];

        return array_merge(
            ['token' => JWT::encode($payload, Config::get('jwt_psk'), 'HS256')],
            $data
        );
    }

    // -----------------------------------------------------------------
    // TOTP (MFA)
    // -----------------------------------------------------------------

    /**
     * generate a new TOTP secret + provisioning URI for a user enrolling in MFA
     *
     * @param string $username shown as the label in authenticator apps
     * @return array ['secret' => ..., 'uri' => ...]
     */
    public static function totpGenerate(string $username): array {
        // 20 bytes / 160 bits -- the size RFC 4226 section 4 recommends, and what
        // authenticator apps expect. otphp's own default is 64 bytes, whose
        // 103-character base32 encoding does not fit users.totp_secret varchar(64)
        // (config/mariadb-schema.sql) and would be silently truncated on write.
        $totp = TOTP::generate(null, 20);
        $totp->setLabel($username);
        $totp->setIssuer('inet-services.it');
        return [
            'secret' => $totp->getSecret(),
            'uri'    => $totp->getProvisioningUri(),
        ];
    }

    /**
     * @param string $secret the user's TOTP secret
     * @param string $code the 6-digit code to check
     * @return bool status
     */
    public static function totpVerify(string $secret, string $code): bool {
        try {
            // leeway in seconds; 29 = just under one 30s period, giving practical ±1-window tolerance
            return TOTP::createFromSecret($secret)->verify($code, null, 29);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // -----------------------------------------------------------------
    // Slim middleware (CORS, trailing-slash normalization, error handling)
    // -----------------------------------------------------------------

    /**
     * register the app's global middleware stack: body-parsing, trailing-slash
     * normalization, error handling, and CORS. Called once from public/index.php
     * right after $app is constructed.
     *
     * @param App $app the Slim application instance
     */
    public static function registerMiddleware(App $app): void {
        // Add the BodyParsingMiddleware in order to automatically parse the Request-Body
        // and provide it as a PHP array/object through $request->getParsedBody()
        $app->addBodyParsingMiddleware();

        $app->add(function (Request $request, RequestHandler $handler): Response {
            $uri  = $request->getUri();
            $path = $uri->getPath();
            if ($path !== '/' && str_ends_with($path, '/')) {
                $request = $request->withUri($uri->withPath(rtrim($path, '/')));
            }
            return $handler->handle($request);
        });

        // Add the ErrorMiddleware before the CORS middleware
        // to ensure error responses contain all CORS headers.
        $app->addErrorMiddleware(true, true, true);

        // This CORS middleware will append the response header
        // Access-Control-Allow-Methods with all allowed methods
        $app->add(function (Request $request, RequestHandler $handler) use ($app): Response {
            $origin = $request->getHeaderLine('Origin');

            // A request without an Origin header is not a browser cross-origin
            // request: curl, cron jobs and fixed-API-token clients all land here.
            // CORS is something browsers enforce on top of an Origin, so with
            // none present there is nothing to police, and an empty
            // Access-Control-Allow-Origin header would be meaningless anyway.
            // This used to be expressed by keeping "" in allowed_origins, which
            // made a security-relevant behaviour hinge on an invisible empty
            // string -- and broke every scripted client the moment an operator
            // configured a real origin list over the placeholder.
            if ($origin === '') {
                $response = $handler->handle($request);
                if (ob_get_contents()) {
                    ob_clean();
                }
                return $response;
            }

            $isAllowed = in_array($origin, Config::get('allowed_origins'), strict: true);

            if ($request->getMethod() === 'OPTIONS') {
                $statusCode = $isAllowed ? 204 : 403;
                $response = $app->getResponseFactory()->createResponse($statusCode);
            } else {
                if (!$isAllowed) {
                    $response = $app->getResponseFactory()->createResponse(403);
                    $response->getBody()->write(json_encode(['error' => "CORS: Origin [{$origin}] not allowed"]));
                    // by NOT calling $handler->handle() the middleware-chain is broken and no further routes will be executed
                    return $response->withHeader('Content-Type', 'application/json');
                }
                $response = $handler->handle($request);
            }

            if ($isAllowed) {
                $response = $response
                    ->withHeader('Access-Control-Allow-Credentials', 'true')
                    ->withHeader('Access-Control-Allow-Origin', $origin)
                    ->withHeader('Access-Control-Allow-Headers', implode(', ', Config::get('allowed_headers')))
                    ->withHeader('Access-Control-Allow-Methods', implode(', ', Config::get('allowed_methods')))
                    ->withHeader('Access-Control-Expose-Headers', 'Content-Disposition') // filename=".."
                    ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                    ->withHeader('Pragma', 'no-cache')
                    ->withHeader('Vary', 'Origin'); // important in case of dynamic origins!
            }

            if (ob_get_contents()) {
                ob_clean();
            }

            return $response;
        });
    }

    // -----------------------------------------------------------------
    // network / client IP
    // -----------------------------------------------------------------

    /**
     * best-effort client IP, preferring X-Forwarded-For (validated as IPv4)
     * over the raw connecting address
     *
     * @return string|false the client's IPv4 address, or false if it couldn't be determined
     */
    public static function clientIp(): string|false {
        $headers = function_exists('apache_request_headers') ? apache_request_headers() : $_SERVER;

        if (array_key_exists('X-Forwarded-For', $headers) && filter_var($headers['X-Forwarded-For'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $headers['X-Forwarded-For'];
        } elseif (array_key_exists('HTTP_X_FORWARDED_FOR', $headers) && filter_var($headers['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $headers['HTTP_X_FORWARDED_FOR'];
        } else {
            return filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        }
    }

    /**
     * @param string $cidr an IPv4 CIDR range, e.g. '127.0.0.1/32'
     * @return bool whether the current client's IP falls inside $cidr
     */
    public static function clientIpInCidr(string $cidr): bool {
        [$network, $bits] = explode('/', $cidr);
        $ip      = ip2long(self::clientIp());
        $network = ip2long($network);
        $mask    = ~((1 << (32 - (int)$bits)) - 1);
        return ($ip & $mask) === ($network & $mask);
    }

    // -----------------------------------------------------------------
    // CSV (for a proper CSV handling class see https://github.com/keboola/php-csv)
    // -----------------------------------------------------------------

    /**
     * skip a UTF-8 byte-order mark at the start of an open file, if present
     *
     * @param resource $fp file pointer, positioned at the very start of the file
     */
    public static function jumpBOM(&$fp): void {
        // Progress file pointer and get first 3 characters to compare to the BOM string.
        if (fgets($fp, 4) !== self::BOM) {
            // BOM not found - rewind pointer to start of file.
            rewind($fp);
        }
    }

    /**
     * @param array $row values to encode
     * @param string $delimiter field delimiter
     * @param string $lineBreak line terminator
     * @param string $enclosure field-quoting character
     * @return string one CSV-encoded row, including the trailing line break
     */
    public static function rowToCSV(array $row, string $delimiter = ',', string $lineBreak = "\n", string $enclosure = '"'): string {
        $return = [];
        foreach ($row as $column) {
            $return[] = $enclosure . str_replace($enclosure, $enclosure.$enclosure, $column) . $enclosure;
        }
        return implode($delimiter, $return) . $lineBreak;
    }

    // -----------------------------------------------------------------
    // request validation
    // -----------------------------------------------------------------

    /**
     * @param array $params request parameters
     * @param array $fields required field names
     * @return string|null error message listing every missing field, or null if none are missing
     */
    public static function requireFields(array $params, array $fields): ?string {
        $missing = [];
        foreach ($fields as $field) {
            if (empty($params[$field])) {
                $missing[] = $field;
            }
        }
        if ( ! empty($missing)) {
            return 'required field(s) missing or empty: ' . implode(', ', $missing);
        }
        return null;
    }

    /**
     * @param array $params request parameters
     * @param array $maxLengths field => max character length
     * @return string|null error message for the first field exceeding its limit, or null if none do
     */
    public static function maxLength(array $params, array $maxLengths): ?string {
        foreach ($maxLengths as $field => $max) {
            if (isset($params[$field]) && is_string($params[$field]) && strlen($params[$field]) > $max) {
                return "{$field} exceeds the maximum length of {$max} characters";
            }
        }
        return null;
    }

    /**
     * @param string $email address to check
     * @return bool status
     */
    public static function isValidEmailFormat(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * basic .it domain name shape check -- not a full RFC-1035 validator,
     * just enough to reject obvious garbage before it reaches the registry
     */
    public static function isValidDomainFormat(string $domain): bool {
        return (bool) preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.it$/i', $domain);
    }
}
