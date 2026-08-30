<?php
/**
 * Job for adding new user messages.
 *
 * @file
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\NewUserMessage;

use MediaWiki\JobQueue\Job;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * @internal
 *
 * Job parameters:
 *   - userId: the user ID
 */
class NewUserMessageJob extends Job {

	public function __construct(
		array $params,
		private readonly UserFactory $userFactory,
		private readonly NewUserMessage $newUserMessage,
	) {
		parent::__construct( 'newUserMessageJob', $params );
	}

	/** @inheritDoc */
	public function run(): bool {
		$user = $this->userFactory->newFromId( $this->params['userId'] );
		$user->load( IDBAccessObject::READ_LATEST );

		if ( !$user->getId() ) {
			return false;
		}

		$this->newUserMessage->createNewUserMessage( $user );

		return true;
	}
}
