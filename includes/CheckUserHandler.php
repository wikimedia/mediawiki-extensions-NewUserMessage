<?php
/**
 * CheckUser hook handler for Extension:NewUserMessage.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\NewUserMessage;

use MediaWiki\Extension\CheckUser\Hook\CheckUserInsertChangesRowHook;
use MediaWiki\Extension\CheckUser\Hook\CheckUserInsertLogEventRowHook;
use MediaWiki\Extension\CheckUser\Hook\CheckUserInsertPrivateEventRowHook;
use MediaWiki\Message\Message;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityUtils;

class CheckUserHandler implements
	CheckUserInsertChangesRowHook,
	CheckUserInsertPrivateEventRowHook,
	CheckUserInsertLogEventRowHook
{
	public function __construct(
		private readonly UserFactory $userFactory,
		private readonly UserIdentityUtils $userIdentityUtils,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onCheckUserInsertChangesRow(
		string &$ip, &$xff, array &$row, UserIdentity $user, ?RecentChange $rc
	): void {
		if ( $this->isNewUserMessageEditor( $user ) ) {
			$ip = '127.0.0.1';
			$xff = false;
			$row['cuc_agent'] = '';
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onCheckUserInsertLogEventRow(
		string &$ip, &$xff, array &$row, UserIdentity $user, int $id, ?RecentChange $rc
	): void {
		if ( $this->isNewUserMessageEditor( $user ) ) {
			$ip = '127.0.0.1';
			$xff = false;
			$row['cule_agent'] = '';
		}
	}

	/**
	 * @inheritDoc
	 */
	public function onCheckUserInsertPrivateEventRow(
		string &$ip, &$xff, array &$row, UserIdentity $user, ?RecentChange $rc
	): void {
		if ( $this->isNewUserMessageEditor( $user ) ) {
			$ip = '127.0.0.1';
			$xff = false;
			$row['cupe_agent'] = '';
		}
	}

	private function isNewUserMessageEditor( UserIdentity $user ): bool {
		if ( !$this->userIdentityUtils->isNamed( $user ) ) {
			return false;
		}

		$editor = $this->userFactory->newFromName(
			$this->getMsg( 'newusermessage-editor' )->text()
		);

		return $editor && $editor->equals( $user );
	}

	private function getMsg( string $name ): Message {
		return wfMessage( $name )->inContentLanguage();
	}
}
