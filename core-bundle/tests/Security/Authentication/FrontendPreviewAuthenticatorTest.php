<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Security\Authentication;

use Contao\CoreBundle\Fixtures\Security\User\ForwardCompatibilityUserProviderInterface;
use Contao\CoreBundle\Security\Authentication\FrontendPreviewAuthenticator;
use Contao\CoreBundle\Security\Authentication\Token\FrontendPreviewToken;
use Contao\CoreBundle\Tests\TestCase;
use Contao\FrontendUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class FrontendPreviewAuthenticatorTest extends TestCase
{
    /**
     * @dataProvider getShowUnpublishedData
     */
    public function testAuthenticatesAFrontendUser(bool $showUnpublished): void
    {
        $security = $this->createMock(Security::class);
        $security
            ->expects($this->once())
            ->method('isGranted')
            ->willReturnOnConsecutiveCalls(true)
        ;

        $session = $this->mockSession();

        $user = $this->createPartialMock(FrontendUser::class, ['getRoles']);
        $user
            ->expects($this->once())
            ->method('getRoles')
            ->willReturn(['ROLE_MEMBER'])
        ;

        $userProvider = $this->createMock(ForwardCompatibilityUserProviderInterface::class);
        $userProvider
            ->method('loadUserByIdentifier')
            ->willReturn($user)
        ;

        $authenticator = $this->getAuthenticator($security, $session, $userProvider);

        $this->assertTrue($authenticator->authenticateFrontendUser('foobar', $showUnpublished));
        $this->assertTrue($session->has('_security_contao_frontend'));

        $token = unserialize($session->get('_security_contao_frontend'), ['allowed_classes' => true]);

        $this->assertInstanceOf(FrontendPreviewToken::class, $token);
        $this->assertInstanceOf(FrontendUser::class, $token->getUser());
        $this->assertSame($showUnpublished, $token->showUnpublished());
    }

    public function getShowUnpublishedData(): \Generator
    {
        yield [true];
        yield [false];
    }

    public function testDoesNotAuthenticateAFrontendUserIfTheUsernameIsInvalid(): void
    {
        $security = $this->createMock(Security::class);
        $security
            ->expects($this->never())
            ->method('isGranted')
        ;

        $userProvider = $this->createMock(ForwardCompatibilityUserProviderInterface::class);
        $userProvider
            ->expects($this->once())
            ->method('loadUserByIdentifier')
            ->willThrowException(new UserNotFoundException())
        ;

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('info')
            ->with('Could not find a front end user with the username "foobar"')
        ;

        $authenticator = $this->getAuthenticator($security, null, $userProvider, $logger);

        $this->assertFalse($authenticator->authenticateFrontendUser('foobar', false));
    }

    public function testDoesNotAuthenticateAFrontendUserIfThereIsNoAllowedMemberGroup(): void
    {
        $security = $this->createMock(Security::class);
        $security
            ->expects($this->once())
            ->method('isGranted')
            ->willReturnOnConsecutiveCalls(false)
        ;

        $user = $this->mockClassWithProperties(FrontendUser::class);
        $user
            ->expects($this->never())
            ->method('getRoles')
            ->willReturn(['ROLE_MEMBER'])
        ;

        $userProvider = $this->createMock(ForwardCompatibilityUserProviderInterface::class);
        $userProvider
            ->method('loadUserByIdentifier')
            ->willReturn($user)
        ;

        $authenticator = $this->getAuthenticator($security, null, $userProvider);

        $this->assertFalse($authenticator->authenticateFrontendUser('foobar', false));
    }

    /**
     * @dataProvider getShowUnpublishedData
     */
    public function testAuthenticatesAFrontendGuest(bool $showUnpublished): void
    {
        $security = $this->createMock(Security::class);
        $security
            ->expects($this->never())
            ->method('isGranted')
        ;

        $session = $this->mockSession();
        $authenticator = $this->getAuthenticator($security, $session);

        $this->assertTrue($authenticator->authenticateFrontendGuest($showUnpublished));
        $this->assertTrue($session->has('_security_contao_frontend'));

        $token = unserialize($session->get('_security_contao_frontend'), ['allowed_classes' => true]);

        $this->assertInstanceOf(FrontendPreviewToken::class, $token);
        $this->assertSame('anon.', $token->getUser()); // @phpstan-ignore-line
        $this->assertSame($showUnpublished, $token->showUnpublished());
    }

    public function testRemovesTheAuthenticationFromTheSession(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(true)
        ;

        $session
            ->expects($this->once())
            ->method('has')
            ->with('_security_contao_frontend')
            ->willReturn(true)
        ;

        $session
            ->expects($this->once())
            ->method('remove')
            ->with('_security_contao_frontend')
        ;

        $authenticator = $this->getAuthenticator(null, $session);

        $this->assertTrue($authenticator->removeFrontendAuthentication());
    }

    public function testDoesNotRemoveTheAuthenticationIfTheSessionIsNotStarted(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(false)
        ;

        $authenticator = $this->getAuthenticator(null, $session);

        $this->assertFalse($authenticator->removeFrontendAuthentication());
    }

    public function testDoesNotRemoveTheAuthenticationIfTheSessionDoesNotContainAToken(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session
            ->expects($this->once())
            ->method('isStarted')
            ->willReturn(true)
        ;

        $session
            ->expects($this->once())
            ->method('has')
            ->with('_security_contao_frontend')
            ->willReturn(false)
        ;

        $authenticator = $this->getAuthenticator(null, $session);

        $this->assertFalse($authenticator->removeFrontendAuthentication());
    }

    private function getAuthenticator(Security $security = null, SessionInterface $session = null, UserProviderInterface $userProvider = null, LoggerInterface $logger = null): FrontendPreviewAuthenticator
    {
        if (null === $session) {
            $session = $this->createMock(SessionInterface::class);
            $session
                ->method('isStarted')
                ->willReturn(true)
            ;
        }

        $security ??= $this->createMock(Security::class);
        $userProvider ??= $this->createMock(ForwardCompatibilityUserProviderInterface::class);
        $logger ??= $this->createMock(LoggerInterface::class);

        return new FrontendPreviewAuthenticator($security, $session, $userProvider, $logger);
    }
}
