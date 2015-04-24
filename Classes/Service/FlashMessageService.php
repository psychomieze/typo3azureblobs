<?php
namespace Neusta\AzureBlobs\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

class FlashMessageService {

	/**
	 * @param $message
	 * @param $title
	 * @param $severity
	 * @return \TYPO3\CMS\Core\Messaging\FlashMessage
	 */
	public function getFlashMessageInstance($message, $title, $severity) {
		return GeneralUtility::makeInstance(
			'TYPO3\CMS\Core\Messaging\FlashMessage',
			$message,
			$title,
			$severity);
	}
}