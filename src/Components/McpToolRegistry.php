<?php

namespace DreamFactory\Core\McpServer\Components;

use Illuminate\Support\Facades\File;

class McpToolRegistry
{
    protected array $tools = [];

    public function __construct()
    {
        $toolPath = base_path('src/Tools');
        if (File::exists($toolPath)) {
            foreach (File::allFiles($toolPath) as $file) {
                $class = 'DreamFactory\\McpServer\\Tools\\' . $file->getFilenameWithoutExtension();
                if (class_exists($class)) {
                    $this->register($class);
                }
            }
        }
    }

    public function register(string $class): void
    {
        $this->tools[] = $class;
    }

    public function all(): array
    {
        return $this->tools;
    }
}
