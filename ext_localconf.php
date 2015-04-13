<?php
if (!defined('TYPO3_MODE')) {
	die('Access denied.');
}

/** @var \TYPO3\CMS\Core\Resource\Driver\DriverRegistry $driverRegistry */
$driverRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\Driver\\DriverRegistry');
require_once(\TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName('EXT:azure_blobs/Classes/Library/WindowsAzure/WindowsAzure.php'));
$driverRegistry->registerDriverClass(
	'Neusta\AzureBlobs\Driver\BlobStorageDriver',
	'AzureBlob',
	'Azure Block Blob Storage',
	'FILE:EXT:azure_blobs/Configuration/FlexForm/AzureBlobsFlexForm.xml'
);

// register extractor
//\TYPO3\CMS\Core\Resource\Index\ExtractorRegistry::getInstance()->registerExtractionService('AUS\AusDriverAmazonS3\Index\Extractor');

/* @var $signalSlotDispatcher \TYPO3\CMS\Extbase\SignalSlot\Dispatcher */
//$signalSlotDispatcher = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\CMS\Extbase\SignalSlot\Dispatcher');
//$signalSlotDispatcher->connect('TYPO3\\CMS\\Core\\Resource\\Index\\FileIndexRepository', 'recordUpdated', 'AUS\AusDriverAmazonS3\Signal\FileIndexRepository', 'recordUpdatedOrCreated');
//$signalSlotDispatcher->connect('TYPO3\\CMS\\Core\\Resource\\Index\\FileIndexRepository', 'recordCreated', 'AUS\AusDriverAmazonS3\Signal\FileIndexRepository', 'recordUpdatedOrCreated');
