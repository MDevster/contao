<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\EventListener\DataContainer\RecordPreviewListener;
use Contao\CoreBundle\Tests\TestCase;
use Contao\DataContainer;
use Contao\DC_Table;
use Contao\System;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;

class RecordPreviewListenerTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        unset($GLOBALS['TL_DCA']);
    }

    /**
     * @dataProvider loadDataContainer
     */
    public function testRegistersDeleteCallbackOnDeletableDataContainers(string $table, bool $notDeletable, ?array $expected): void
    {
        $GLOBALS['TL_DCA'][$table]['config']['notDeletable'] = $notDeletable;

        $framework = $this->mockContaoFramework();
        $connection = $this->createMock(Connection::class);

        $listener = new RecordPreviewListener($framework, $connection);
        $listener->registerDeleteCallbacks($table);

        if ($notDeletable) {
            $this->assertArrayNotHasKey('ondelete_callback', $GLOBALS['TL_DCA'][$table]['config']);
        } else {
            $this->assertSame($expected, $GLOBALS['TL_DCA'][$table]['config']['ondelete_callback']);
        }
    }

    public function testPrecompilesRecordPreviewWithLabelFunction(): void
    {
        $GLOBALS['TL_DCA']['tl_form']['list']['sorting']['mode'] = DataContainer::MODE_SORTED;

        $row = [
            'id' => '42',
        ];

        $framework = $this->mockContaoFramework([
            System::class => $this->createMock(System::class),
        ]);

        $result = $this->createMock(Result::class);
        $result
            ->method('fetchAssociative')
            ->willReturn($row)
        ;

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('quoteIdentifier')
            ->with('tl_form')
            ->willReturn('tl_form')
        ;

        $connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM tl_form WHERE id = ?', ['42'])
            ->willReturn($result)
        ;

        $connection
            ->expects($this->once())
            ->method('update')
            ->with(
                'tl_undo',
                ['preview' => '<record-preview>'],
                ['id' => '42']
            )
        ;

        $dataContainer = $this->mockClassWithProperties(DC_Table::class, [
            'id' => '42',
            'table' => 'tl_form',
        ]);

        $dataContainer
            ->expects($this->once())
            ->method('generateRecordLabel')
            ->with($row, 'tl_form')
            ->willReturn('<record-preview>')
        ;

        $listener = new RecordPreviewListener($framework, $connection);
        $listener->storePrecompiledRecordPreview($dataContainer, '42');
    }

    public function testPrecompilesRecordPreviewWithLabelFunctionAndShowColumns(): void
    {
        $GLOBALS['TL_DCA']['tl_user']['list']['sorting']['mode'] = DataContainer::MODE_SORTED;
        $GLOBALS['TL_DCA']['tl_user']['list']['label']['showColumns'] = true;

        $row = [
            'id' => '42',
            'username' => 'foo',
            'email' => 'foo@example.org',
        ];

        $framework = $this->mockContaoFramework([
            System::class => $this->createMock(System::class),
        ]);

        $result = $this->createMock(Result::class);
        $result
            ->method('fetchAssociative')
            ->willReturn($row)
        ;

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('quoteIdentifier')
            ->with('tl_user')
            ->willReturn('tl_user')
        ;

        $connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM tl_user WHERE id = ?', ['42'])
            ->willReturn($result)
        ;

        $connection
            ->expects($this->once())
            ->method('update')
            ->with(
                'tl_undo',
                ['preview' => serialize(['42', 'foo', 'foo@example.org'])],
                ['id' => '42']
            )
        ;

        $dataContainer = $this->mockClassWithProperties(DC_Table::class, [
            'id' => '42',
            'table' => 'tl_user',
        ]);

        $dataContainer
            ->expects($this->once())
            ->method('generateRecordLabel')
            ->with($row, 'tl_user')
            ->willReturn(['42', 'foo', 'foo@example.org'])
        ;

        $listener = new RecordPreviewListener($framework, $connection);
        $listener->storePrecompiledRecordPreview($dataContainer, '42');
    }

    public function testPrecompilesRecordPreviewWithCallable(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['list']['sorting']['mode'] = DataContainer::MODE_PARENT;
        $GLOBALS['TL_DCA']['tl_content']['list']['sorting']['child_record_callback'] = ['childRecordListener', 'callback'];

        $row = [
            'id' => '42',
        ];

        $childRecordListener = $this->mockAdapter(['callback']);
        $childRecordListener
            ->expects($this->once())
            ->method('callback')
            ->with($row)
            ->willReturn('<record-preview>')
        ;

        $systemAdapter = $this->mockAdapter([
            'importStatic',
        ]);

        $systemAdapter
            ->expects($this->once())
            ->method('importStatic')
            ->with('childRecordListener')
            ->willReturn($childRecordListener)
        ;

        $framework = $this->mockContaoFramework([
            System::class => $systemAdapter,
        ]);

        $result = $this->createMock(Result::class);
        $result
            ->method('fetchAssociative')
            ->willReturn($row)
        ;

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('quoteIdentifier')
            ->with('tl_content')
            ->willReturn('tl_content')
        ;

        $connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM tl_content WHERE id = ?', ['42'])
            ->willReturn($result)
        ;

        $connection
            ->expects($this->once())
            ->method('update')
            ->with(
                'tl_undo',
                ['preview' => '<record-preview>'],
                ['id' => '42']
            )
        ;

        $dataContainer = $this->mockClassWithProperties(DC_Table::class, [
            'id' => '42',
            'table' => 'tl_content',
        ]);

        $dataContainer
            ->expects($this->never())
            ->method('generateRecordLabel')
        ;

        $listener = new RecordPreviewListener($framework, $connection);
        $listener->storePrecompiledRecordPreview($dataContainer, '42');
    }

    public function testPrecompilesRecordPreviewWithCallback(): void
    {
        $GLOBALS['TL_DCA']['tl_content']['list']['sorting']['mode'] = DataContainer::MODE_PARENT;
        $GLOBALS['TL_DCA']['tl_content']['list']['sorting']['child_record_callback'] = static fn () => '<record-preview>';

        $row = [
            'id' => '42',
        ];

        $framework = $this->mockContaoFramework([
            System::class => $this->createMock(System::class),
        ]);

        $result = $this->createMock(Result::class);
        $result
            ->method('fetchAssociative')
            ->willReturn($row)
        ;

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('quoteIdentifier')
            ->with('tl_content')
            ->willReturn('tl_content')
        ;

        $connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM tl_content WHERE id = ?', ['42'])
            ->willReturn($result)
        ;

        $connection
            ->expects($this->once())
            ->method('update')
            ->with(
                'tl_undo',
                ['preview' => '<record-preview>'],
                ['id' => '42']
            )
        ;

        $dataContainer = $this->mockClassWithProperties(DC_Table::class, [
            'id' => '42',
            'table' => 'tl_content',
        ]);

        $dataContainer
            ->expects($this->never())
            ->method('generateRecordLabel')
        ;

        $listener = new RecordPreviewListener($framework, $connection);
        $listener->storePrecompiledRecordPreview($dataContainer, '42');
    }

    public function testHandlesExceptionsGracefully(): void
    {
        $GLOBALS['TL_DCA']['tl_form']['list']['sorting']['mode'] = DataContainer::MODE_SORTED;

        $framework = $this->mockContaoFramework([
            System::class => $this->createMock(System::class),
        ]);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('executeQuery')
            ->willThrowException(new \Exception('exception'))
        ;

        $connection
            ->expects($this->once())
            ->method('update')
            ->with(
                'tl_undo',
                ['preview' => ''],
                ['id' => '42']
            )
        ;

        $dataContainer = $this->mockClassWithProperties(DC_Table::class, [
            'id' => '42',
            'table' => 'tl_form',
        ]);

        $dataContainer
            ->expects($this->never())
            ->method('generateRecordLabel')
        ;

        $listener = new RecordPreviewListener($framework, $connection);
        $listener->storePrecompiledRecordPreview($dataContainer, '42');
    }

    public function loadDataContainer(): \Generator
    {
        yield 'Non-deletable data container' => [
            'tl_optin',
            true,
            null,
        ];

        yield 'Deletable data container' => [
            'tl_page',
            false,
            [['contao.listener.data_container.record_preview', 'storePrecompiledRecordPreview']],
        ];
    }
}
