<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Doctrine\Backup\Config;

use Contao\CoreBundle\Doctrine\Backup\Backup;

abstract class AbstractConfig
{
    private Backup $backup;
    private array $tablesToIgnore = [];
    private bool $gzCompression;

    public function __construct(Backup $backup)
    {
        $this->backup = $backup;

        // Enable gz compression by default if path ends on .gz
        $this->gzCompression = 0 === strcasecmp(substr($backup->getFilepath(), -3), '.gz');
    }

    public function getTablesToIgnore(): array
    {
        return $this->tablesToIgnore;
    }

    public function getBackup(): Backup
    {
        return $this->backup;
    }

    public function isGzCompressionEnabled(): bool
    {
        return $this->gzCompression;
    }

    /**
     * @return static
     */
    public function withGzCompression(bool $enable)/*: static*/
    {
        $new = clone $this;
        $new->gzCompression = $enable;

        return $new;
    }

    /**
     * @return static
     */
    public function withTablesToIgnore(array $tablesToIgnore)/*: static*/
    {
        $new = clone $this;
        $new->tablesToIgnore = $this->filterTablesToIgnore($new->tablesToIgnore, $tablesToIgnore);

        return $new;
    }

    /**
     * @return static
     */
    public function withFilePath(string $filePath)/*: static*/
    {
        $new = clone $this;
        $new->backup = new Backup($filePath);

        return $new;
    }

    private function filterTablesToIgnore(array $currentTables, array $newTables): array
    {
        $newList = array_filter(
            $newTables,
            static fn ($table) => !\in_array($table[0], ['-', '+'], true)
        );

        if ($newList) {
            $currentTables = $newList;
        }

        foreach ($newTables as $newTable) {
            $prefix = $newTable[0];
            $table = substr($newTable, 1);

            if ('-' === $prefix && \in_array($table, $currentTables, true)) {
                unset($currentTables[array_search($table, $currentTables, true)]);
            } elseif ('+' === $prefix && !\in_array($table, $currentTables, true)) {
                $currentTables[] = $table;
            }
        }

        sort($currentTables);

        return $currentTables;
    }
}
