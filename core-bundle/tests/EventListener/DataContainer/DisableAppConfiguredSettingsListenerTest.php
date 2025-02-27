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

use Contao\CoreBundle\EventListener\DataContainer\DisableAppConfiguredSettingsListener;
use Contao\CoreBundle\ServiceAnnotation\Callback;
use Contao\CoreBundle\Tests\TestCase;
use Contao\Image;
use Doctrine\Common\Annotations\AnnotationReader;
use Symfony\Contracts\Translation\TranslatorInterface;

class DisableAppConfiguredSettingsListenerTest extends TestCase
{
    public function testAnnotatedCallbacks(): void
    {
        $listener = $this->createListener();
        $annotationReader = new AnnotationReader();

        /** @var Callback $annotation */
        $annotation = $annotationReader->getClassAnnotation(new \ReflectionClass($listener), Callback::class);

        $this->assertSame('tl_settings', $annotation->table);
        $this->assertSame('config.onload', $annotation->target);
    }

    public function testLoadCallbackExitsOnMissingLocalconfigParameter(): void
    {
        $GLOBALS['TL_DCA']['tl_settings'] = [];
        $before = $GLOBALS['TL_DCA']['tl_settings'];

        $listener = $this->createListener();
        $listener->onLoadCallback();

        $this->assertSame($before, $GLOBALS['TL_DCA']['tl_settings']);
    }

    public function testLoadCallbackDisablesSettingsConfiguredByBundleConfiguration(): void
    {
        $GLOBALS['TL_DCA']['tl_settings']['fields'] = [
            'adminEmail' => [
                'inputType' => 'text',
                'eval' => [
                    'mandatory' => true,
                    'rgxp' => 'friendly',
                    'decodeEntities' => true,
                    'tl_class' => 'w50',
                ],
            ],
            'dateFormat' => [
                'inputType' => 'text',
                'eval' => [
                    'mandatory' => true,
                    'helpwizard' => true,
                    'decodeEntities' => true,
                    'tl_class' => 'w50',
                ],
                'explanation' => 'dateFormat',
            ],
        ];

        $listener = $this->createListener(
            [
                'adminEmail' => 'admin@example.org',
                'dateFormat' => 'd.M.Y',
                'fooBar' => false,
            ]
        );
        $listener->onLoadCallback();

        $this->assertSame(
            [
                'adminEmail' => [
                    'inputType' => 'text',
                    'eval' => [
                        'mandatory' => true,
                        'rgxp' => 'friendly',
                        'decodeEntities' => true,
                        'tl_class' => 'w50',
                        'disabled' => true,
                        'helpwizard' => false,
                    ],
                    'xlabel' => [['contao.listener.data_container.disable_app_configured_settings', 'renderHelpIcon']],
                ],
                'dateFormat' => [
                    'inputType' => 'text',
                    'eval' => [
                        'mandatory' => true,
                        'helpwizard' => false,
                        'decodeEntities' => true,
                        'tl_class' => 'w50',
                        'disabled' => true,
                    ],
                    'explanation' => 'dateFormat',
                    'xlabel' => [['contao.listener.data_container.disable_app_configured_settings', 'renderHelpIcon']],
                ],
            ],
            $GLOBALS['TL_DCA']['tl_settings']['fields']
        );
    }

    public function testRenderHelpIcon(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->expects($this->once())
            ->method('trans')
            ->with('tl_settings.configuredInApp', [], 'contao_tl_settings')
        ;

        $imageAdapter = $this->mockAdapter(['getHtml']);
        $imageAdapter
            ->expects($this->once())
            ->method('getHtml')
            ->willReturn('<img src="system/themes/icons/show.svg" alt="" title="title">')
        ;

        $listener = $this->createListener(null, $translator, [Image::class => $imageAdapter]);

        $this->assertSame(
            '<img src="system/themes/icons/show.svg" alt="" title="title">',
            $listener->renderHelpIcon()
        );
    }

    private function createListener(array $localConfig = null, TranslatorInterface $translator = null, array $adapters = []): DisableAppConfiguredSettingsListener
    {
        $this->mockContaoFramework()->initialize();

        $translator ??= $this->createMock(TranslatorInterface::class);
        $framework = $this->mockContaoFramework($adapters);

        return new DisableAppConfiguredSettingsListener($translator, $framework, $localConfig ?: []);
    }
}
