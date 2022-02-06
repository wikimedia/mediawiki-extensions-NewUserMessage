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

use ContentHandler;
use DeferredUpdates;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use Message;
use ParserOptions;
use Title;
use User;
use WikiPage;

class NewUserMessage {
	/**
	 * Produce the editor for new user messages.
	 * @return User|bool
	 */
	private static function fetchEditor() {
		// Create a user object for the editing user and add it to the
		// database if it is not there already
		$editor = User::newFromName( self::getMsg( 'newusermessage-editor' )->text() );

		if ( !$editor ) {
			return false; // Invalid username
		}

		if ( !$editor->isRegistered() ) {
			$editor->addToDatabase();
		}

		return $editor;
	}

	/**
	 * Produce a (possibly random) signature.
	 * @return string
	 */
	private static function fetchSignature() {
		$signatures = self::getMsg( 'newusermessage-signatures' )->text();
		$signature = '';

		if ( !self::getMsg( 'newusermessage-signatures' )->isDisabled() ) {
			$pattern = '/^\* ?(.*?)$/m';
			$signatureList = [];
			preg_match_all( $pattern, $signatures, $signatureList, PREG_SET_ORDER );
			if ( count( $signatureList ) > 0 ) {
				$rand = rand( 0, count( $signatureList ) - 1 );
				$signature = $signatureList[$rand][1];
			}
		}

		return $signature;
	}

	/**
	 * Return the template name if it exists, or '' otherwise.
	 * @param string $template string with page name of user message template
	 * @return string
	 */
	private static function fetchTemplateIfExists( $template ) {
		$text = Title::newFromText( $template );

		if ( !$text ) {
			wfDebug( __METHOD__ . ": '$template' is not a valid title.\n" );
			return '';
		} elseif ( $text->getNamespace() !== NS_TEMPLATE ) {
			wfDebug( __METHOD__ . ": '$template' is not a valid Template.\n" );
			return '';
		} elseif ( !$text->exists() ) {
			return '';
		}

		return $text->getText();
	}

	/**
	 * Produce a subject for the message.
	 * @return string
	 */
	private static function fetchSubject() {
		return self::fetchTemplateIfExists(
			self::getMsg( 'newusermessage-template-subject' )->text()
		);
	}

	/**
	 * Produce the template that contains the text of the message.
	 * @return string
	 */
	private static function fetchText() {
		$template = self::getMsg( 'newusermessage-template-body' )->text();

		$title = Title::newFromText( $template );
		if ( $title && $title->exists() && $title->getLength() ) {
			return $template;
		}

		// Fall back if necessary to the old template
		return self::getMsg( 'newusermessage-template' )->text();
	}

	/**
	 * Produce the flags to set on WikiPage::doUserEditContent
	 * @return int
	 */
	private static function fetchFlags() {
		global $wgNewUserMinorEdit, $wgNewUserSuppressRC;

		$flags = EDIT_NEW;
		if ( $wgNewUserMinorEdit ) {
			$flags |= EDIT_MINOR;
		}
		if ( $wgNewUserSuppressRC ) {
			$flags |= EDIT_SUPPRESS_RC;
		}

		return $flags;
	}

	/**
	 * Take care of substition on the string in a uniform manner
	 * @param string $str
	 * @param User $user
	 * @param User $editor
	 * @param Title $talk
	 * @param string|null $preparse If provided, then preparse the string using a Parser
	 * @return string
	 */
	private static function substString( $str, $user, $editor, $talk, $preparse = null ) {
		$realName = $user->getRealName();
		$name = $user->getName();

		// Add (any) content to [[MediaWiki:Newusermessage-substitute]] to substitute the
		// welcome template.
		$substDisabled = self::getMsg( 'newusermessage-substitute' )->isDisabled();

		if ( $substDisabled ) {
			$str = '{{' . "$str|realName=$realName|name=$name}}";
		} else {
			$str = '{{subst:' . "$str|realName=$realName|name=$name}}";
		}

		if ( $preparse ) {
			$str = MediaWikiServices::getInstance()->getParser()->preSaveTransform(
				$str,
				$talk,
				$editor,
				new ParserOptions( $user )
			);
		}

		return $str;
	}

	/**
	 * Add the message if the users talk page does not already exist
	 * @param User $user User object
	 * @return bool
	 */
	public static function createNewUserMessage( $user ) {
		$talk = $user->getTalkPage();

		// Only leave message if user doesn't have a talk page yet
		if ( !$talk->exists() ) {
			$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $talk );
			$subject = self::fetchSubject();
			$text = self::fetchText();
			$signature = self::fetchSignature();
			$editSummary = self::getMsg( 'newuseredit-summary' )->text();
			$editor = self::fetchEditor();
			$flags = self::fetchFlags();

			# Do not add a message if the username is invalid or if the account that adds it,
			# is blocked
			if ( !$editor || $editor->getBlock() ) {
				return true;
			}

			if ( $subject ) {
				$subject = self::substString( $subject, $user, $editor, $talk, "preparse" );
			}
			if ( $text ) {
				$text = self::substString( $text, $user, $editor, $talk );
			}

			self::leaveUserMessage( $user, $wikiPage, $subject, $text,
				$signature, $editSummary, $editor, $flags );
		}
		return true;
	}

	/**
	 * Hook function to create new user pages when an account is created or autocreated
	 * @param User $user object of the user
	 * @param bool $autocreated
	 * @return bool
	 */
	public static function onLocalUserCreated( User $user, $autocreated ) {
		global $wgNewUserMessageOnAutoCreate;

		if ( !$autocreated ) {
			DeferredUpdates::addCallableUpdate(
				static function () use ( $user ) {
					if ( $user->isBot() ) {
						return; // not a human
					}

					NewUserMessage::createNewUserMessage( $user );
				},
				DeferredUpdates::PRESEND
			);
		} elseif ( $wgNewUserMessageOnAutoCreate ) {
			MediaWikiServices::getInstance()->getJobQueueGroup()->lazyPush(
				new NewUserMessageJob( [ 'userId' => $user->getId() ] ) );
		}

		return true;
	}

	/**
	 * Hook function to provide a reserved name
	 * @param array &$names
	 * @return bool
	 */
	public static function onUserGetReservedNames( &$names ) {
		$names[] = 'msg:newusermessage-editor';
		return true;
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
	public static function leaveUserMessage( $user, $wikiPage, $subject, $text, $signature,
			$summary, $editor, $flags
	) {
		$text = self::formatUserMessage( $subject, $text, $signature );
		$flags = $wikiPage->checkFlags( $flags );

		if ( $flags & EDIT_UPDATE ) {
			$content = $wikiPage->getContent( RevisionRecord::RAW );
			if ( $content !== null ) {
				$text = $content->getNativeData() . "\n" . $text;
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
	protected static function formatUserMessage( $subject, $text, $signature ) {
		$contents = "";
		$signature = empty( $signature ) ? "~~~~" : "{$signature} ~~~~~";

		if ( $subject ) {
			$contents .= "== $subject ==\n\n";
		}
		$contents .= "$text\n\n-- $signature\n";

		return $contents;
	}

	/**
	 * @param string $name
	 * @return Message
	 */
	protected static function getMsg( $name ) {
		return wfMessage( $name )->inContentLanguage();
	}
}
