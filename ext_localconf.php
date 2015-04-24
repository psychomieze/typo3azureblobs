<?php
if (!defined('TYPO3_MODE')) {
	die('Access denied.');
}

/** @var \TYPO3\CMS\Core\Resource\Driver\DriverRegistry $driverRegistry */
$driverRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\Driver\\DriverRegistry');
$driverRegistry->registerDriverClass(
	'Neusta\AzureBlobs\Driver\BlobStorageDriver',
	'AzureBlob',
	'Azure Block Blob Storage',
	'FILE:EXT:azure_blobs/Configuration/FlexForm/AzureBlobsFlexForm.xml'
);
