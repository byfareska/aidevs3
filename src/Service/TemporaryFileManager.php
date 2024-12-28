<?php declare(strict_types=1);

namespace App\Service;

final class TemporaryFileManager
{
    private array $files = [];

    public function createFilePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tfm_');
        $this->files[] = $path;

        return $path;
    }

    public function __destruct()
    {
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}