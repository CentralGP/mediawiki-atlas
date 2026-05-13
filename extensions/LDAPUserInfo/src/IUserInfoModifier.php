<?php

namespace MediaWiki\Extension\LDAPUserInfo;

use MediaWiki\User\User;
use Status;

interface IUserInfoModifier {

	/**
	 *
	 * @param User $user
	 * @param string $rawValue
	 * @return Status
	 */
	public function modifyUserInfo( $user, $rawValue );
}
