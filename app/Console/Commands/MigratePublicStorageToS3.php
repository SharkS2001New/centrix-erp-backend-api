<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigratePublicStorageToS3 extends Command
{
    protected $signature = 'storage:migrate-public-to-s3
                            {--dry-run : List files without uploading}
                            {--source= : Local directory override (default: storage/app/public)}';

    protected $description = 'Copy existing public-disk files from local disk to S3/MinIO';

    public function handle(): int
    {
        if (config('filesystems.disks.public.driver') !== 's3') {
            $this->error('PUBLIC_STORAGE_DRIVER must be s3. Set AWS_* env vars and PUBLIC_STORAGE_DRIVER=s3 first.');

            return self::FAILURE;
        }

        $sourceRoot = $this->option('source') ?: storage_path('app/public');
        if (! is_dir($sourceRoot)) {
            $this->warn("Source directory does not exist: {$sourceRoot}");

            return self::SUCCESS;
        }

        $remote = Storage::disk('public');
        $files = $this->collectFiles($sourceRoot, $sourceRoot);
        $this->info('Found '.count($files).' file(s) under '.$sourceRoot);

        $uploaded = 0;
        $skipped = 0;

        foreach ($files as $relativePath) {
            if ($remote->exists($relativePath)) {
                $skipped++;
                $this->line("skip  {$relativePath}");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("would upload  {$relativePath}");

                continue;
            }

            $absolute = $sourceRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $stream = fopen($absolute, 'rb');
            if ($stream === false) {
                $this->warn("unable to read  {$relativePath}");

                continue;
            }

            $remote->put($relativePath, $stream);
            fclose($stream);
            $uploaded++;
            $this->line("uploaded  {$relativePath}");
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run complete.');
        } else {
            $this->info("Done. uploaded={$uploaded} skipped={$skipped}");
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function collectFiles(string $root, string $directory): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $absolute = $file->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($root))), '/');
            if ($relative !== '') {
                $paths[] = $relative;
            }
        }

        sort($paths);

        return $paths;
    }
}
