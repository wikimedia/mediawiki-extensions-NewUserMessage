<?php
/**
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\NewUserMessage\Tests\Integration\Hooks;

use MediaWiki\Extension\NewUserMessage\CheckUserHandler;
use MediaWiki\User\UserIdentityValue;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\NewUserMessage\CheckUserHandler
 */
class CheckUserHandlerTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'CheckUser' );
	}

	/**
	 * @dataProvider provideInsertHooks
	 */
	public function testInsertHook( string $method, string $agentField ): void {
		$services = $this->getServiceContainer();
		$handler = new CheckUserHandler(
			$services->getUserFactory(),
			$services->getUserIdentityUtils()
		);
		$editorName = $services->getUserFactory()->newFromName(
			wfMessage( 'newusermessage-editor' )->inContentLanguage()->text()
		)->getName();

		$users = [
			'editor' => UserIdentityValue::newRegistered( 1, $editorName ),
			'registered' => UserIdentityValue::newRegistered( 2, 'Another user' ),
			'temporary' => new UserIdentityValue( 3, '*12345' ),
			'anonymous' => UserIdentityValue::newAnonymous( '192.0.2.1' ),
		];

		foreach ( $users as $kind => $user ) {
			$ip = '192.0.2.2';
			$xff = '192.0.2.3';
			$row = [ 'other_field' => 'unchanged', $agentField => 'Browser' ];

			if ( $method === 'onCheckUserInsertLogEventRow' ) {
				$handler->$method( $ip, $xff, $row, $user, 1, null );
			} else {
				$handler->$method( $ip, $xff, $row, $user, null );
			}

			if ( $kind === 'editor' ) {
				$this->assertSame( '127.0.0.1', $ip );
				$this->assertFalse( $xff );
				$this->assertSame( [ 'other_field' => 'unchanged', $agentField => '' ], $row );
			} else {
				$this->assertSame( '192.0.2.2', $ip );
				$this->assertSame( '192.0.2.3', $xff );
				$this->assertSame( [ 'other_field' => 'unchanged', $agentField => 'Browser' ], $row );
			}
		}
	}

	public static function provideInsertHooks(): array {
		return [
			'edit' => [ 'onCheckUserInsertChangesRow', 'cuc_agent' ],
			'log event' => [ 'onCheckUserInsertLogEventRow', 'cule_agent' ],
			'private event' => [ 'onCheckUserInsertPrivateEventRow', 'cupe_agent' ],
		];
	}
}
