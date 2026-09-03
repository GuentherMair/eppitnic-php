<?php

namespace Eppitnic\Epp\Transport;

/**
 * Cookie handling for the EPP-over-HTTPS transport: parsing `Set-Cookie`
 * headers out of a raw response, and building the `Cookie:` header a request
 * sends back. Pure and static, so the parsing is testable without a Transport.
 *
 * LICENSE:
 *
 * Copyright (c) Günther Mair <info@inet-services.it>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1) Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 * 2) Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 * 3) Neither the name of Günther Mair nor the names of its contributors may be
 *    used to endorse or promote products derived from this software without
 *    specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @category    Net
 * @package     Eppitnic\Epp\Transport\Cookies
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class Cookies
{
    /**
     * Every `Set-Cookie: name=value[; attr...]` in a raw HTTP header block,
     * keyed by name (a later one overwrites an earlier one, as a browser's
     * jar would). Matched case-insensitively -- servers are not consistent
     * about `Set-Cookie` vs `set-cookie` -- and a cookie is terminated at the
     * first `;` *or* line ending, since one with no attributes has no `;` at
     * all.
     *
     * @param string $headers the raw header block, as Curl::getHttpHeaders()
     *               returns it
     * @return array<string, string> cookie name => value
     */
    public static function parse(string $headers): array {
        $cookies = [];
        $pos = 0;

        while (($start = stripos($headers, 'Set-Cookie: ', $pos)) !== false) {
            $start += strlen('Set-Cookie: ');

            $end = strcspn($headers, ";\r\n", $start);
            $cookie = substr($headers, $start, $end);
            $pos = $start + $end;

            $eq = strpos($cookie, '=');
            if ($eq !== false) {
                $name = trim(substr($cookie, 0, $eq));
                $value = trim(substr($cookie, $eq + 1));
                if ($name !== '') {
                    $cookies[$name] = $value;
                }
            }
        }

        return $cookies;
    }

    /**
     * The `Cookie:` request header value for a stored jar.
     *
     * @param array<string, string> $cookies name => value
     * @return string e.g. "a=1; b=2", or '' for an empty jar
     */
    public static function header(array $cookies): string {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = "{$name}={$value}";
        }
        return implode('; ', $pairs);
    }
}
