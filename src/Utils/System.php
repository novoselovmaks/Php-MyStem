<?php


namespace Sheronov\PhpMyStem\Utils;


use BadFunctionCallException;
use Exception;
use PharData;
use RuntimeException;
use Sheronov\PhpMyStem\Exceptions\MyStemException;
use Sheronov\PhpMyStem\Exceptions\MyStemNotFoundException;
use ZipArchive;

class System
{
    protected const FAMILY_WINDOWS = 'Windows';
    protected const FAMILY_LINUX   = 'Linux';
    protected const FAMILY_MACOS   = 'Darwin';

    protected const BIN_PATH    = 'bin';
    protected const WINDOWS_BIN = 'windows'.DIRECTORY_SEPARATOR.'mystem.exe';
    protected const LINUX_BIN   = 'linux'.DIRECTORY_SEPARATOR.'mystem';
    protected const MACOS_BIN   = 'macos'.DIRECTORY_SEPARATOR.'mystem';

    /**
     * Running MyStem with input as pipe to proc through php proc_open
     *
     * @param  string  $input
     * @param  array  $arguments
     * @param  string|null  $myStemPath
     *
     * @return string
     * @throws MyStemException
     * @throws MyStemNotFoundException
     */
    public static function runMyStem(string $input, array $arguments = [], string $myStemPath = null): string
    {
        if (!isset($myStemPath)) {
            $myStemPath = self::myStemPath();
        }

        if (!empty($arguments)) {
            $myStemPath .= ' '.implode(' ', $arguments);
        }

        dd($myStemPath);
        
        $descriptorSpec = [
            ['pipe', 'r'], //0 - stdIn
            ['pipe', 'w'], //1 - stdOut
            ['pipe', 'w'], //2 - stdErr
        ];

        $process = proc_open($myStemPath, $descriptorSpec, $pipes, null, null);

        if (!is_resource($process)) {
            throw new BadFunctionCallException('There is no "proc_open" function in your system');
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stdErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        if (!empty($stdErr)) {
            throw new MyStemException($stdErr);
        }

        proc_close($process);

        return $output;
    }

    public static function downloadMystem(array $oses = [], bool $fromConsole = false, bool $reRun = false): void
    {
        if (empty($oses)) {
            $oses = ['l', 'w', 'm'];
        }
        $composerJsonPath = dirname(__FILE__, 3).DIRECTORY_SEPARATOR.'composer.json';
        if (!file_exists($composerJsonPath)) {
            throw new RuntimeException('File not found '.$composerJsonPath);
        }
        $toPath = dirname(__FILE__, 3).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR;
        $composerJson = json_decode(file_get_contents($composerJsonPath), true);

        $distUrls = $composerJson['extra']['dists'] ?? [];

        foreach ($distUrls as $os => $url) {
            if (in_array(mb_strtolower(mb_substr($os, 0, 1)), $oses, true)) {
                $localPath = $toPath.basename($url);

                if (!file_exists($localPath)) {
                    if (self::downloadFile($url, $localPath)) {
                        if ($fromConsole) {
                            echo 'Success downloaded file from '.$url.' for '.$os.PHP_EOL;
                        }
                    } else {
                        throw new RuntimeException('Error download file '.$url.' for '.$os);
                    }
                }

                if (file_exists($toPath.mb_strtolower($os))) {
                    self::recursiveDelete($toPath.mb_strtolower($os));
                }

                if (self::isArchive($localPath)) {
                    try {
                        self::unArchive($localPath, mb_strtolower($os));
                    } catch (Exception $exception) {
                        if (!$reRun) {
                            unlink($localPath);
                            self::downloadMystem($oses, $fromConsole, true);
                        }
                        return;
                    }
                    if ($fromConsole) {
                        echo 'Success unarchived to '.$toPath.mb_strtolower($os).' directory'.PHP_EOL;
                    }
                    try {
                        unlink($localPath);
                    } catch (Exception $exception) {
                        if ($fromConsole) {
                            echo 'Can not delete file '.$localPath.PHP_EOL;
                        }
                    }
                    self::changeFilesModeToExecute($toPath.mb_strtolower($os));
                }
            }
        }
    }

    protected static function changeFilesModeToExecute(string $path): void
    {
        $files = glob($path.DIRECTORY_SEPARATOR.'*');
        foreach ($files as $file) {
            chmod($file, 0555);
        }
    }

    protected static function recursiveDelete(string $path): bool
    {
        if (is_file($path)) {
            return unlink($path);
        }
        $deleted = false;
        $deletedFiles = true;
        $files = glob($path.DIRECTORY_SEPARATOR.'*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $deletedFiles = $deletedFiles && unlink($file);
            } else {
                $deletedFiles = $deletedFiles && self::recursiveDelete($file);
            }
        }
        if ($deletedFiles) {
            $deleted = rmdir($path);
        }
        return $deleted;
    }


    protected static function unArchive(string $filePath, string $prefix): bool
    {
        $pathInfo = pathinfo($filePath);
        $extractTo = $pathInfo['dirname'].DIRECTORY_SEPARATOR.$prefix;
        $extension = $pathInfo['extension'] ?? null;

        switch ($extension) {
            case 'zip':
                $zipArchive = new ZipArchive();
                $zipArchive->open($filePath);
                $result = $zipArchive->extractTo($extractTo);
                $zipArchive->close();
                break;
            case 'gz':
                $phar = new PharData($filePath);
                $phar->decompress();
                $result = self::unArchive(mb_substr($filePath, 0, mb_strlen($filePath) - mb_strlen('.gz')), $prefix);
                break;
            case 'tar':
                $phar = new PharData($filePath);
                $result = $phar->extractTo($extractTo);
                unlink($filePath);
                break;
            default:
                throw new RuntimeException('Wrong archive extension '.$filePath);
        }

        return $result;
    }

    protected static function isArchive(string $path): bool
    {
        return in_array(pathinfo($path)['extension'] ?? null, ['zip', 'tar', 'gz'], true);
    }

    protected static function downloadFile(string $url, string $localPath): bool
    {
        $fp = fopen($localPath, 'wb');

        if ($fp === false) {
            throw new RuntimeException('Can not create file here '.$localPath);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $result = curl_exec($ch);

        curl_close($ch);
        fclose($fp);

        return $result;
    }

    /**
     * @return string
     * @throws MyStemNotFoundException
     */
    protected static function myStemPath(): string
    {
        $binPath = self::binPath();

        switch (true) {
            case self::isWindows():
                $binaryPath = $binPath.self::WINDOWS_BIN;
                $letter = 'w';
                break;

            case self::isLinux():
                $binaryPath = $binPath.self::LINUX_BIN;
                $letter = 'l';
                break;

            case self::isMacos():
                $binaryPath = $binPath.self::MACOS_BIN;
                $letter = 'm';
                break;

            default:
                throw new RuntimeException("Wrong OS");
        }

        if (!file_exists($binaryPath)) {
            // attempt to download from first run
            self::downloadMystem([$letter]);
        }

        if (!file_exists($binaryPath)) {
            throw new MyStemNotFoundException('The bin file myStem does not exist');
        }

        return $binaryPath;
    }

    protected static function binPath(): string
    {
        return dirname(__FILE__, 3).DIRECTORY_SEPARATOR.self::BIN_PATH.DIRECTORY_SEPARATOR;
    }

    protected static function isWindows(): bool
    {
        return PHP_OS_FAMILY === self::FAMILY_WINDOWS;
    }

    protected static function isLinux(): bool
    {
        return PHP_OS_FAMILY === self::FAMILY_LINUX;
    }

    protected static function isMacos(): bool
    {
        return PHP_OS_FAMILY === self::FAMILY_MACOS;
    }

}
