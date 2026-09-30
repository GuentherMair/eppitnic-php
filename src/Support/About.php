<?php

namespace Eppitnic\Support;

/**
 * What this installation is made of: the application's identity and the
 * runtime packages Composer installed, for the "About" screen.
 *
 * @category    Net
 * @package     Eppitnic\Support\About
 * @author      Günther Mair <info@inet-services.it>
 * @license     http://opensource.org/licenses/bsd-license.php New BSD License
 */
final class About
{
    /**
     * The version is APP_VERSION; the dependency licenses are joined with
     * " OR " ('' when a package names none).
     *
     * @return array{name: string, version: string, license: string, copyright: string,
     *               dependencies: list<array{name: string, version: string, license: string}>}
     */
    public static function info(): array {
        return [
            'name'         => 'eppitnic',
            'version'      => self::version(),
            'license'      => 'BSD-3-Clause',
            'copyright'    => self::copyright(),
            'dependencies' => self::dependencies(),
        ];
    }

    private static function version(): string {
        return APP_VERSION;
    }

    private static function copyright(): string {
        $license = (string) @file_get_contents(EPPITNIC_ROOT . '/LICENSE');
        return preg_match('/^Copyright .+$/m', $license, $m) ? $m[0] : '';
    }

    /** @return list<array{name: string, version: string, license: string}> */
    private static function dependencies(): array {
        $installed = json_decode((string) @file_get_contents(EPPITNIC_ROOT . '/vendor/composer/installed.json'), true);
        $dev = array_flip($installed['dev-package-names'] ?? []);

        $dependencies = [];
        foreach ($installed['packages'] ?? [] as $package) {
            if (isset($dev[$package['name']])) {
                continue;
            }
            $dependencies[] = [
                'name'    => $package['name'],
                'version' => $package['pretty_version'] ?? $package['version'] ?? '',
                'license' => implode(' OR ', (array) ($package['license'] ?? [])),
            ];
        }
        usort($dependencies, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
        return $dependencies;
    }
}
