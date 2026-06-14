<?php

/** Extension:NewUserMessage
 *
 * @file
 * @ingroup Extensions
 *
 * @author [http://www.organicdesign.co.nz/nad User:Nad]
 * @license GPL-2.0-or-later
 * @copyright 2007-10-15 [http://www.organicdesign.co.nz/nad User:Nad]
 * @copyright 2009 Siebrand Mazeland
 */

namespace MediaWiki\Extension\NewUserMessage;

use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Config\Config;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\TextContent;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Message\Message;
use MediaWiki\Page\WikiPage;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use MediaWiki\User\User;
use MediaWiki\User\UserEditTracker;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;

class NewUserMessage implements
	LocalUserCreatedHook,
	PageSaveCompleteHook,
	UserGetReservedNamesHook
{
	public function __construct(
		private readonly Config $config,
		private readonly UserFactory $userFactory,
		private readonly UserEditTracker $userEditTracker,
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly TitleFactory $titleFactory,
		private readonly Parser $parser,
	) {
	}

	/**
	 * Produce the editor for new user messages.
	 */
	private function fetchEditor(): User|false {
		$editor = $this->userFactory->newFromName(
			$this->getMsg( 'newusermessage-editor' )->text()
		);

		if ( !$editor ) {
			// Invalid username
			return false;
		}

		if ( !$editor->isRegistered() ) {
			$editor->addToDatabase();
		}

		return $editor;
	}

	/**
	 * Produce a (possibly random) signature.
	 */
	private function fetchSignature(): string {
		$signature = '';
		$signaturesMsg = $this->getMsg( 'newusermessage-signatures' );

		if ( !$signaturesMsg->isDisabled() ) {
			$pattern = '/^\* ?(.*?)$/m';
			$signatureList = [];
			$signatures = $signaturesMsg->text();
			preg_match_all( $pattern, $signatures, $signatureList, PREG_SET_ORDER );

			if ( count( $signatureList ) > 0 ) {
				$signature = $signatureList[mt_rand( 0, count( $signatureList ) - 1 )][1];
			}
		}

		return $signature;
	}

	/**
	 * Return the template name if it exists, or '' otherwise.
	 */
	private function fetchTemplateIfExists( string $template ): string {
		$title = $this->titleFactory->newFromText( $template );

		if ( !$title ) {
			wfDebug( __METHOD__ . ": '$template' is not a valid title.\n" );
			return '';
		} elseif ( $title->getNamespace() !== NS_TEMPLATE ) {
			wfDebug( __METHOD__ . ": '$template' is not a valid Template.\n" );
			return '';
		} elseif ( !$title->exists() ) {
			return '';
		}

		return $title->getText();
	}

	/**
	 * Produce a subject for the message.
	 */
	private function fetchSubject(): string {
		return $this->fetchTemplateIfExists(
			$this->getMsg( 'newusermessage-template-subject' )->text()
		);
	}

	/**
	 * Produce the template that contains the text of the message.
	 */
	private function fetchText(): string {
		$template = $this->getMsg( 'newusermessage-template-body' )->text();

		$title = $this->titleFactory->newFromText( $template );
		if ( $title && $title->exists() && $title->getLength() ) {
			return $template;
		}

		// Fall back if necessary to the old template
		return $this->getMsg( 'newusermessage-template' )->text();
	}

	/**
	 * Produce the flags to set on WikiPage::doUserEditContent
	 */
	private function fetchFlags(): int {
		$flags = EDIT_NEW;
		if ( $this->config->get( 'NewUserMinorEdit' ) ) {
			$flags |= EDIT_MINOR;
		}
		if ( $this->config->get( 'NewUserSuppressRC' ) ) {
			$flags |= EDIT_SUPPRESS_RC;
		}

		return $flags;
	}

	/**
	 * Take care of substitution on the string in a uniform manner
	 *
	 * if $preparse is true, preparse the string using a Parser
	 */
	private function substString(
		string $str,
		User $user,
		User $editor,
		Title $talk,
		bool $preparse = false
	): string {
		$realName = $user->getRealName();
		$name = $user->getName();

		// Add (any) content to [[MediaWiki:Newusermessage-substitute]] to substitute the
		// welcome template.
		$substDisabled = $this->getMsg( 'newusermessage-substitute' )->isDisabled();

		if ( $substDisabled ) {
			$str = '{{' . "$str|realName=$realName|name=$name}}";
		} else {
			$str = '{{subst:' . "$str|realName=$realName|name=$name}}";
		}

		if ( $preparse ) {
			$str = $this->parser->preSaveTransform(
				$str,
				$talk,
				$editor,
				ParserOptions::newFromUser( $user )
			);
		}

		return $str;
	}

	/**
	 * Add the message if the user's talk page does not already exist
	 */
	public function createNewUserMessage( User $user ): bool {
		$talk = $user->getTalkPage();

		// Only leave message if user doesn't have a talk page yet
		if ( !$talk->exists() ) {
			$editor = $this->fetchEditor();

			// Do not add a message if the username is invalid or if the account that adds it,
			// is blocked
			if ( !$editor || $editor->getBlock() ) {
				return true;
			}

			$wikiPage = $this->wikiPageFactory->newFromTitle( $talk );
			$subject = $this->fetchSubject();
			$text = $this->fetchText();
			$signature = $this->fetchSignature();
			$editSummary = $this->getMsg( 'newuseredit-summary' )->text();
			$flags = $this->fetchFlags();

			if ( $subject ) {
				$subject = $this->substString( $subject, $user, $editor, $talk, true );
			}
			if ( $text ) {
				$text = $this->substString( $text, $user, $editor, $talk );
			}

			$this->leaveUserMessage(
				$user, $wikiPage, $subject, $text, $signature, $editSummary, $editor, $flags
			);
		}
		return true;
	}

	/**
	 * Hook function to create new user pages when an account is created or autocreated
	 * @param User $user object of the user
	 * @param bool $autocreated
	 */
	public function onLocalUserCreated( $user, $autocreated ): void {
		if ( $user->isTemp() ) {
			// not a new registered user
			return;
		}

		if ( !$autocreated ) {
			DeferredUpdates::addCallableUpdate(
				function () use ( $user ) {
					if ( $user->isBot() ) {
						// not a human
						return;
					}

					$this->createNewUserMessage( $user );
				},
				DeferredUpdates::PRESEND
			);
		} elseif ( $this->config->get( 'NewUserMessageOnAutoCreate' ) ) {
			$this->jobQueueGroup->lazyPush(
				new JobSpecification(
					'newUserMessageJob',
					[ 'userId' => $user->getId() ]
				)
			);
		}
	}

	/**
	 * Hook function to send a welcome message to autocreated users on their first
	 * non-imported edit, when $wgNewUserMessageOnFirstEdit is enabled.
	 *
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $user
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 */
	public function onPageSaveComplete(
		$wikiPage, $user, $summary, $flags, $revisionRecord, $editResult
	): void {
		if ( !$this->config->get( 'NewUserMessageOnFirstEdit' ) ) {
			return;
		}

		$fullUser = $this->userFactory->newFromUserIdentity( $user );
		if ( !$fullUser->isNamed() ) {
			return;
		}

		$editCount = $this->userEditTracker->getUserEditCount( $fullUser );
		// PageSaveComplete runs after the triggering edit has already been counted,
		// so the user's first edit has edit count 1 here
		if ( $editCount > 1 ) {
			return;
		}

		DeferredUpdates::addCallableUpdate(
			function () use ( $fullUser ) {
				if ( $fullUser->isBot() ) {
					return;
				}
				$this->createNewUserMessage( $fullUser );
			},
			DeferredUpdates::POSTSEND
		);
	}

	/**
	 * Hook function to provide a reserved name
	 * @param array &$names
	 */
	public function onUserGetReservedNames( &$names ): void {
		$names[] = 'msg:newusermessage-editor';
	}

	/**
	 * Leave a user a message
	 * @param User $user User to message
	 * @param WikiPage $wikiPage user talk page
	 * @param string $subject string with the subject of the message
	 * @param string $text string with the message to leave
	 * @param string $signature string to leave in the signature
	 * @param string $summary string with the summary for this change, defaults to
	 *                        "Leave system message."
	 * @param User $editor User leaving the message, defaults to
	 *                        "{{MediaWiki:usermessage-editor}}"
	 * @param int $flags default edit flags
	 *
	 * @return bool true if it was successful
	 */
	public function leaveUserMessage(
		User $user,
		WikiPage $wikiPage,
		string $subject,
		string $text,
		string $signature,
		string $summary,
		User $editor,
		int $flags
	): bool {
		$text = $this->formatUserMessage( $subject, $text, $signature );
		$flags = $wikiPage->checkFlags( $flags );

		if ( $flags & EDIT_UPDATE ) {
			$content = $wikiPage->getContent( RevisionRecord::RAW );
			if ( $content !== null && $content instanceof TextContent ) {
				$text = $content->getText() . "\n" . $text;
			}
		}

		$status = $wikiPage->doUserEditContent(
			ContentHandler::makeContent( $text, $wikiPage->getTitle() ),
			$editor,
			$summary,
			$flags
		);
		return $status->isGood();
	}

	/**
	 * Format the user message using a hook, a template, or, failing these, a static format.
	 * @param string $subject the subject of the message
	 * @param string $text the content of the message
	 * @param string $signature the signature, if provided.
	 * @return string in wiki text with complete user message
	 */
	protected function formatUserMessage( string $subject, string $text, string $signature ): string {
		$contents = "";
		$signature = $signature === '' ? "~~~~" : "{$signature} ~~~~~";

		if ( $subject ) {
			$contents .= "== $subject ==\n\n";
		}
		$contents .= "$text\n\n-- $signature\n";

		return $contents;
	}

	protected function getMsg( string $name ): Message {
		return wfMessage( $name )->inContentLanguage();
	}
}
