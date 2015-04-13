<?php
/**
 * Created by PhpStorm.
 * User: susanne
 * Date: 20.04.15
 * Time: 08:23
 */

namespace Neusta\AzureBlobs\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

class FlashMessageService {

	public function getFlashMessageInstance($message, $title, $severity) {
		return GeneralUtility::makeInstance(
			'TYPO3\CMS\Core\Messaging\FlashMessage',
			$message,
			$title,
			$severity);
	}
}