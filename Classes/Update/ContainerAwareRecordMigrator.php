<?php

declare(strict_types=1);

namespace WEBcoast\MigratorToContainer\Update;

use WEBcoast\Migrator\Update\RecordDataMigrator;

abstract class ContainerAwareRecordMigrator extends RecordDataMigrator
{
    protected function moveIntoContainer(int|string $recordUid, int|string $containerId, int $colPos, null|int|string $after = null): void
    {
        $this->move($recordUid, [
            'update' => [
                'tx_container_parent' => $containerId,
                'colPos' => $colPos,
            ],
            'action' => 'paste',
            'target' => $after ? '-' . $after : $containerId,
        ]);
    }
}
