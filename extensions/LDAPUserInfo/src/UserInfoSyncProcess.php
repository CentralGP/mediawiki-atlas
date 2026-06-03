<?php

namespace MediaWiki\Extension\LDAPUserInfo;

use LogicException;
use MediaWiki\Config\Config as MediaWikiConfig;
use MediaWiki\Extension\LDAPProvider\Client;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use Throwable;

class UserInfoSyncProcess {

	/**
	 *
	 * @var User
	 */
	private $user = null;

	/**
	 *
	 * @var MediaWikiConfig
	 */
	private $domainConfig = null;

	/**
	 *
	 * @var array
	 */
	protected $callbackRegistry = [];

	/**
	 *
	 * @var Client
	 */
	private $client = null;

	/**
	 *
	 * @var Status
	 */
	private $status = null;

	/**
	 * UserInfoSyncProcess constructor.
	 * @param User $user
	 * @param MediaWikiConfig $domainConfig
	 * @param \MediaWiki\Extension\LDAPProvider\Client $client
	 * @param array $callbackRegistry
	 */
	public function __construct( $user, $domainConfig, $client, $callbackRegistry ) {
		$this->user = $user;
		$this->domainConfig = $domainConfig;
		$this->client = $client;
		$this->callbackRegistry = $callbackRegistry;
	}

	/**
	 * @return Status
	 */
	public function run() {
		try {
			$this->doSync();
		} catch ( Throwable $ex ) {
			$logger = LoggerFactory::getInstance( 'LDAPUserInfo' );
			$logger->error( $ex->getMessage() );
			return Status::newFatal( $ex->getMessage() );
		}

		return Status::newGood();
	}

	/**
	 * @return bool
	 * @throws LogicException
	 */
	private function doSync() {
		$logger = LoggerFactory::getInstance( 'LDAPUserInfo' );

		$userInfo = $this->client->getUserInfo( $this->user->getName() );
		$attributesMap = $this->domainConfig->get( Config::ATTRIBUTES_MAP );
		$modifierRegistry = $this->callbackRegistry;
		$hasChanges = false;

		foreach ( $attributesMap as $modifierKey => $ldapAttribute ) {
			if ( !isset( $userInfo[$ldapAttribute] ) ) {
				$logger->warning( "No attribute '$ldapAttribute' set in LDAP result!", $userInfo );
				continue;
			}

			$origModifierKey = $modifierKey;
			if ( !isset( $modifierRegistry[$modifierKey] ) ) {
				// "property.gender" --> "property.*"
				$modifierKey = preg_replace( '#^(.*?)\..*?#', '$1.*', $modifierKey );
				if ( !isset( $modifierRegistry[$modifierKey] ) ) {
					throw new LogicException( "No factory callback set for '$modifierKey'!" );
				}
			}
			$factoryCallback = $modifierRegistry[$modifierKey];
			$modifier = call_user_func_array(
				$factoryCallback,
				[
					$origModifierKey,
					$this->domainConfig
				]
			);
			if ( $modifier instanceof IUserInfoModifier === false ) {
				throw new LogicException( "Object from '$origModifierKey' callback does not "
					. "implement `IUserInfoModifier`!" );
			}

			if (
				!$modifier instanceof IUserInfoConditionalModifier ||
				$modifier->shouldModifyUserInfo( $this->user, $userInfo[$ldapAttribute ] )
			) {
				$hasChanges = true;
				$logger->info( "Set '$origModifierKey' with raw value {$userInfo[$ldapAttribute]}" );

				$attributeValue = $this->getFromUserInfo( $userInfo, $ldapAttribute );

				$modifier->modifyUserInfo( $this->user, $attributeValue );
			}
		}

		if ( $hasChanges ) {
			$this->user->saveSettings();
		}

		return true;
	}

	/**
	 * @param array $result
	 * @param string $key
	 * @return string
	 */
	private function getFromUserInfo( $result, $key ) {
		$value = '';
		if ( !isset( $result[$key] ) ) {
			return $value;
		}
		$value = $result[$key];
		if ( is_array( $value ) ) {
			$firstItemKey = array_key_first( $value );
			$value = $value[$firstItemKey];
		}
		return $value;
	}
}
