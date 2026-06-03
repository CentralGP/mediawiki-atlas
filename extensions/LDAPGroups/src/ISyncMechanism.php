<?php

namespace MediaWiki\Extension\LDAPGroups;

use MediaWiki\Config\Config;
use MediaWiki\Status\Status;
use MediaWiki\User\User;

interface ISyncMechanism {

	/**
	 * @param User $user
	 * @param \MediaWiki\Extension\LDAPProvider\GroupList $groupList
	 * @param Config $config
	 * @return Status
	 */
	public function sync( $user, $groupList, $config );
}
