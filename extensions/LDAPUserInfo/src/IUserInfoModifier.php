<?php

namespace MediaWiki\Extension\LDAPUserInfo;

use MediaWiki\Status\Status;
use MediaWiki\User\User;

interface IUserInfoModifier {

	/**
	 *
	 * @param User $user
	 * @param string $rawValue
	 * @return Status
	 */
	public function modifyUserInfo( $user, $rawValue );
}
