<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Contao;

use Contao\Model\Collection;
use Contao\PageModel;
use Contao\RootPageDependentSelect;
use Contao\System;
use Contao\TestCase\ContaoTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class RootPageDependentSelectTest extends ContaoTestCase
{
    public function testRendersMultipleSelects(): void
    {
        $rootPages = [
            $this->mockPageModel(['id' => 1, 'title' => 'Root Page 1', 'language' => 'en']),
            $this->mockPageModel(['id' => 2, 'title' => 'Root Page 2', 'language' => 'de']),
            $this->mockPageModel(['id' => 3, 'title' => 'Root Page 3', 'language' => 'fr']),
        ];

        $pageAdapter = $this->mockAdapter(['findByType']);
        $pageAdapter
            ->expects($this->once())
            ->method('findByType')
            ->with('root', ['order' => 'sorting'])
            ->willReturn(new Collection($rootPages, 'tl_page'))
        ;

        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->once())
            ->method('trans')
            ->with('tl_module.rootPageDependentModulesBlankOptionLabel', [], 'contao_module')
            ->willReturn('Please choose your module for %s')
        ;

        $container = $this->getContainerWithContaoConfiguration();
        $container->set('contao.framework', $this->mockContaoFramework([PageModel::class => $pageAdapter]));
        $container->set('translator', $translator);

        System::setContainer($container);

        $fieldConfig = [
            'name' => 'rootPageDependentModules',
            'options' => [
                '10' => 'Module-10',
                '20' => 'Module-20',
                '30' => 'Module-30',
            ],
            'eval' => [
                'includeBlankOption' => true,
            ],
        ];

        $widget = new RootPageDependentSelect(RootPageDependentSelect::getAttributesFromDca($fieldConfig, $fieldConfig['name']));

        $expectedOutput =
            <<<'OUTPUT'
                <select
                    name="rootPageDependentModules[]"
                    id="ctrl_rootPageDependentModules-1"
                    class="tl_select tl_chosen"
                    onfocus="Backend.getScrollOffset()"
                >
                    <option value="">Please choose your module for Root Page 1 (en)</option>
                    <option value="10">Module-10</option>
                    <option value="20">Module-20</option>
                    <option value="30">Module-30</option></select
                ><select
                    name="rootPageDependentModules[]"
                    id="ctrl_rootPageDependentModules-2"
                    class="tl_select tl_chosen"
                    onfocus="Backend.getScrollOffset()"
                >
                    <option value="">Please choose your module for Root Page 2 (de)</option>
                    <option value="10">Module-10</option>
                    <option value="20">Module-20</option>
                    <option value="30">Module-30</option></select
                ><select
                    name="rootPageDependentModules[]"
                    id="ctrl_rootPageDependentModules-3"
                    class="tl_select tl_chosen"
                    onfocus="Backend.getScrollOffset()"
                >
                    <option value="">Please choose your module for Root Page 3 (fr)</option>
                    <option value="10">Module-10</option>
                    <option value="20">Module-20</option>
                    <option value="30">Module-30</option>
                </select>
                OUTPUT;

        $minifiedExpectedOutput = preg_replace(['/\s\s|\n/', '/\s</'], ['', '<'], $expectedOutput);

        $this->assertSame($minifiedExpectedOutput, $widget->generate());
    }

    private function mockPageModel(array $properties): PageModel
    {
        $model = $this->mockClassWithProperties(PageModel::class);

        foreach ($properties as $key => $property) {
            $model->$key = $property;
        }

        return $model;
    }
}
