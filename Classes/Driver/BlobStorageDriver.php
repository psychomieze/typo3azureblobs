<?php
namespace Neusta\AzureBlobs\Driver;

use TYPO3\CMS\Core\Messaging\FlashMessage;
use Neusta\AzureBlobs\Service\FlashMessageService;

use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use WindowsAzure\Common\ServicesBuilder;
use WindowsAzure\Common\ServiceException;
use WindowsAzure\Blob\Models\CreateContainerOptions;
use WindowsAzure\Blob\Models\PublicAccessType;
use WindowsAzure\Blob\Models as AzureModels;

class BlobStorageDriver extends \TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver {


	public function dumpFileContents($identifier) {
		// TODO: Implement dumpFileContents() method.
	}

	protected $container = 'mycontainer';
	protected $storageAccountKey;
	protected $storageAccountName = 'typo3test123';
	protected $protocol = 'https';
	protected $messageQueueByIdentifier;

	/**
	 * @var \Neusta\AzureBlobs\Service\FLashMessageService
	 */
	protected $flashMessageService;

	/**
	 * @var \WindowsAzure\Blob\Internal\IBlob
	 */
	protected $blobRestProxy = NULL;


	/**
	 * @return string
	 */
	public function getContainer() {
		return $this->container;
	}


	/**
	 * @return mixed
	 */
	public function getStorageAccountKey() {
		return $this->storageAccountKey;
	}


	/**
	 * @return string
	 */
	public function getStorageAccountName() {
		return $this->storageAccountName;
	}

	/**
	 * @return string
	 */
	public function getProtocol() {
		return $this->protocol;
	}

	/**
	 * @return \TYPO3\CMS\Core\Messaging\FlashMessageQueue
	 */
	public function getMessageQueueByIdentifier() {
		if (!is_object($this->messageQueueByIdentifier)) {
			/** @var \TYPO3\CMS\Core\Messaging\FlashMessageService $flashMessageService */
			$flashMessageService = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Messaging\FlashMessageService::class);
			$this->messageQueueByIdentifier = $flashMessageService->getMessageQueueByIdentifier();
		}
		return $this->messageQueueByIdentifier;
	}

	/**
	 * @param mixed $messageQueueByIdentifier
	 */
	public function setMessageQueueByIdentifier($messageQueueByIdentifier) {
		$this->messageQueueByIdentifier = $messageQueueByIdentifier;
	}

	/**
	 * @return FlashMessageService
	 */
	public function getFlashMessageService() {
		if (!is_object($this->flashMessageService)) {
			$this->flashMessageService = new FlashMessageService();
		}
		return $this->flashMessageService;
	}

	/**
	 * @param mixed $flashMessageService
	 */
	public function setFlashMessageService(FlashMessageService $flashMessageService) {
		$this->flashMessageService = $flashMessageService;
	}

	public function setStorageAccountName($storageAccountName) {
		$this->storageAccountName = $storageAccountName;
	}

	public function setProtocol($protocol) {
		$this->protocol = $protocol;
	}


	public function setContainer($container) {
		$this->container = $container;
	}


	/*******************************************************************************************************************
	 ***************************************** INTERFACE IMPLEMENTATION: ***********************************************
	 *******************************************************************************************************************/

	public function __construct(array $configuration = []) {
		$this->configuration = $configuration;
	}

	public function initialize(\WindowsAzure\Blob\Internal\IBlob $blobRestProxy = NULL) {
		if($blobRestProxy === NULL) {
			try{
				$this->blobRestProxy =  ServicesBuilder::getInstance()->createBlobService($this->constructConnectionString());
			} catch (\Exception $e) {
				$flashMessage = $this->getFlashMessageService()->getFlashMessageInstance($e->getMessage(), 'Error!', FlashMessage::ERROR);
				$this->getMessageQueueByIdentifier()->enqueue($flashMessage);
			}
		} else {
			$this->blobRestProxy = $blobRestProxy;
		}
		$this->processConfiguration();
		$this->createContainer();
		$this->capabilities = ResourceStorage::CAPABILITY_BROWSABLE | ResourceStorage::CAPABILITY_PUBLIC | ResourceStorage::CAPABILITY_WRITABLE;
	}

	public function processConfiguration() {
		$this->container = $this->getConfigurationValue('containerName');
		$this->storageAccountName = $this->getConfigurationValue('accountName');
		$this->storageAccountKey = $this->getConfigurationValue('accountKey');
		$this->protocol = $this->getConfigurationValue('defaultEndpointsProtocol');
	}

	public function createObject($name, $content = ' ', $options = NULL) {
		if(!is_string($content)) {
			throw new \InvalidArgumentException('$content was not of type string');
		}
		$this->blobRestProxy->createBlockBlob($this->container, $name, $content, $options);
	}

	public function addFile($localFilePath, $targetFolderIdentifier, $newFileName = '', $removeOriginal = TRUE) {
		$newFileName = $newFileName ? $newFileName : basename($localFilePath);
		$targetFolderIdentifier = $this->normalizeFolderName($targetFolderIdentifier);
		$fileIdentifier = $targetFolderIdentifier . $newFileName;

		$fileInfo = finfo_open(FILEINFO_MIME_TYPE);
		$contentType = finfo_file($fileInfo, $localFilePath);
		finfo_close($fileInfo);

		$options = new AzureModels\CreateBlobOptions;
		$options->setContentType($contentType);

		$this->createObject($fileIdentifier, file_get_contents($localFilePath), $options);
		return $fileIdentifier;
	}


	public function createFolder($newFolderName, $parentFolderIdentifier = '', $recursive = TRUE) {
		$parentFolderIdentifier = $this->normalizeFolderName($parentFolderIdentifier);
		$newFolderName = $this->normalizeFolderName($newFolderName);
		$newFolderIdentifier = $this->normalizeFolderName($parentFolderIdentifier . $newFolderName);
		$this->createObject($newFolderIdentifier);
	}

	public function fileExists($fileIdentifier) {
		if($this->isFolder($fileIdentifier)) {
			return FALSE;
		}
		return (bool)$this->getBlob($fileIdentifier);
	}

	public function deleteFile($fileIdentifier) {
		try{
			$this->blobRestProxy->deleteBlob('mycontainer', $fileIdentifier);
			return TRUE;
		} catch (ServiceException $e) {
			return FALSE;
		}
	}

	public function renameFolder($folderIdentifier, $newName) {
		$newTargetParentFolderName = $this->normalizeFolderName(dirname($folderIdentifier));
		$newTargetFolderName = $this->normalizeFolderName($newName);
		$folderIdentifier = $this->normalizeFolderName($folderIdentifier);
		$this->moveFolderWithinStorage($folderIdentifier, $newTargetParentFolderName, $newTargetFolderName);
		return $newTargetParentFolderName . $newTargetFolderName;
	}

	public function getFileContents($fileIdentifier) {
		$blob = $this->getBlob($fileIdentifier);
		return stream_get_contents($blob->getContentStream());
	}

	public function setFileContents($fileIdentifier, $contents) {
		$this->blobRestProxy->createBlockBlob($this->container, $fileIdentifier, $contents);
	}

	public function fileExistsInFolder($fileName, $folderIdentifier) {
		$folderIdentifier = $this->normalizeFolderName($folderIdentifier);
		$blob = $this->getBlob($folderIdentifier . $fileName);
		if($blob) {
			return TRUE;
		}
		return FALSE;
	}

	public function folderExistsInFolder($folderName, $folderIdentifier) {
		$folderIdentifier = $this->normalizeFolderName($folderIdentifier);
		$folderName = $this->normalizeFolderName($folderName);
		$blob = $this->getBlob( $this->normalizeFolderName($folderIdentifier . $folderName));
		if($blob) {
			return TRUE;
		} else {
			return FALSE;
		}
	}

	public function getFileForLocalProcessing($fileIdentifier, $writable = TRUE) {
		$blob = $this->blobRestProxy->getBlob("mycontainer", $fileIdentifier);
		$source = stream_get_contents($blob->getContentStream());
		$temporaryPath = $this->getTemporaryPathForFile($fileIdentifier);
		$result = file_put_contents($temporaryPath, $source);
		if ($result === FALSE) {
			throw new \RuntimeException('Writing file ' . $fileIdentifier . ' to temporary path failed.', 1320577649);
		}
		return $temporaryPath;
	}

	public function moveFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $newFileName) {
		$targetFolderIdentifier = $this->normalizeFolderName($targetFolderIdentifier);
		$targetName = $this->normalizeFolderName($targetFolderIdentifier) . $newFileName;
		$this->move($fileIdentifier, $targetName);
		return $targetName;
	}

	public function moveFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName){
		return $this->moveOrCopyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName, 'move');
	}

	public function copyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName){
		return $this->moveOrCopyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName, 'copy');
	}

	public function isWithin($folderIdentifier, $identifier) {
		if ($folderIdentifier === '') {
			return TRUE;
		}
		$folderIdentifier = $this->normalizeFolderName($folderIdentifier);
		return GeneralUtility::isFirstPartOfStr($identifier, $folderIdentifier);
	}


	public function folderExists($folderIdentifier) {
		$folderIdentifier = $this->normalizeFolderName($folderIdentifier);
		if($folderIdentifier === $this->normalizeFolderName($this->getRootLevelFolder())) {
			return TRUE;
		}
		$blob = $this->getBlob( $folderIdentifier);
		if ($blob) {
			return TRUE;
		} else {
			return FALSE;
		}
	}

	public function getFileInfoByIdentifier($fileIdentifier, array $propertiesToExtract = array()) {
		$fileInfo = [];
		if($fileIdentifier === '') {
			$properties = $this->blobRestProxy->getContainerProperties($this->container);
		} else {
			$blob = $this->getBlob($fileIdentifier);
			$properties = $blob->getProperties();
			$fileInfo['size'] = $properties->getContentLength();
		}
		return array_merge($fileInfo, array(
			'identifier' => $fileIdentifier,
			'name' => basename(rtrim($fileIdentifier, '/')),
			'storage' => $this->storageUid,
			'identifier_hash' => $this->hash($fileIdentifier, ''),
			'folder_hash' => $this->hash(dirname($fileIdentifier), ''),
			'mtime' => $properties->getLastModified()->format('U'),
		));
	}

	public function getFolderInfoByIdentifier($folderIdentifier) {
		$folderIdentifier = $this->normalizeFolderName($folderIdentifier);
		return $this->getFileInfoByIdentifier($folderIdentifier);
	}

	/**
	 * @param string $folderIdentifier
	 * @param int $start @TODO
	 * @param int $numberOfItems @TODO
	 * @param bool $recursive
	 * @param array $filenameFilterCallbacks @TODO
	 * @return mixed
	 */
	public function getFilesInFolder($folderIdentifier, $start = 0, $numberOfItems = 0, $recursive = FALSE, array $filenameFilterCallbacks = []) {
		$files = [];
		$folderIdentifier = $this->normalizeFolderName($folderIdentifier);
		$options = new AzureModels\ListBlobsOptions();
		$options->setPrefix($folderIdentifier);
		$blobListResult = $this->blobRestProxy->listBlobs($this->container, $options);
		$blobs = is_object($blobListResult) ? $blobListResult->getBlobs() : [];
		foreach($blobs as $blob) {
			$fileName = $blob->getName();
			if(substr($fileName, -1) === '/') {
				// folder
				continue;
			}
			if ($recursive === FALSE && substr_count($fileName, '/') > substr_count($folderIdentifier, '/')) {
				// in subfolders
				continue;
			}
			$files[$fileName] = $fileName;
		}
		return $files;
	}

	public function isFolderEmpty($folderIdentifier) {
		$folderIdentifier = $this->normalizeFolderName($folderIdentifier);
		$options = new AzureModels\ListBlobsOptions();
		$options->setPrefix($folderIdentifier);
		$blobListResult = $this->blobRestProxy->listBlobs($this->container, $options);
		$blobs = $blobListResult->getBlobs();
		if (count($blobs) > 0) {
			return FALSE;
		} else {
			return TRUE;
		}
	}

	public function createFile($fileName, $parentFolderIdentifier) {
		$parentFolderIdentifier = $this->normalizeFolderName($parentFolderIdentifier);
		$newIdentifier = $parentFolderIdentifier . $fileName;
		$this->createObject($newIdentifier, chr(26));
		return $newIdentifier;
	}

	public function copyFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $fileName) {
		$targetFolderIdentifier = $this->normalizeFolderName($targetFolderIdentifier);
		$targetFileName = $targetFolderIdentifier . $fileName;
		$this->blobRestProxy->copyBlob($this->container, $targetFileName, $this->container, $fileIdentifier);
		return $targetFileName;
	}

	public function renameFile($fileIdentifier, $newName) {
		$targetFolder = $this->normalizeFolderName(dirname($fileIdentifier));
		$this->moveFileWithinStorage($fileIdentifier, $targetFolder, $newName);
		return $targetFolder . $newName;
	}

	public function replaceFile($fileIdentifier, $localFilePath) {
		$targetFolder = $this->normalizeFolderName(dirname($fileIdentifier));
		$newName = basename($fileIdentifier);
		$this->addFile($localFilePath, $targetFolder, $newName);
	}

	public function getPermissions($identifier) {
		return ['r' => TRUE, 'w' => TRUE];
	}

	public function getFoldersInFolder($folderIdentifier, $start = 0, $numberOfItems = 0, $recursive = FALSE, array $folderNameFilterCallbacks = []) {
		$folderNames = [];
		$folderIdentifier = $this->normalizeFolderName($folderIdentifier);
		$options = new AzureModels\ListBlobsOptions();
		$options->setPrefix($folderIdentifier);
		$blobListResult = $this->blobRestProxy->listBlobs($this->container, $options);
		$blobs = $blobListResult->getBlobs();
		foreach($blobs as $blob) {
			$blobName = $blob->getName();
			if($blobName === $folderIdentifier) {
				continue;
			}
			if(substr($blobName, -1) === '/') {
				if ($recursive === FALSE && substr_count($blobName, '/') > substr_count($folderIdentifier, '/') + 1) {
					continue;
				}
				$folderNames[$blobName] = $blobName;
			}
		}
		return $folderNames;
	}

	public function hash($fileIdentifier, $hashAlgorithm) {
		return sha1($fileIdentifier);
	}

	public function hashIdentifier($identifier) {
		return sha1($identifier);
	}

	public function getPublicUrl($identifier) {
		return $this->protocol . '://' . $this->storageAccountName . '.blob.core.windows.net/' . $this->container . '/' . $identifier;
	}


	public function getRootLevelFolder() {
		return '/';
	}

	public function mergeConfigurationCapabilities($capabilities) {
		$this->capabilities &= $capabilities;
		return $this->capabilities;
	}

	/**
	 * Returns the identifier of the default folder new files should be put into.
	 *
	 * @TODO configurable
	 * @return string
	 */
	public function getDefaultFolder() {
		return $this->getRootLevelFolder();
	}

	public function deleteFolder($folderIdentifier, $deleteRecursively = TRUE){
		$sourceFolderIdentifier = $this->normalizeFolderName($folderIdentifier);
		$blobs = $this->getBlobsFromIdentifier($sourceFolderIdentifier);
		foreach($blobs as $blob) {
			$this->blobRestProxy->deleteBlob($this->container, $blob->getName());
		}
	}


	/*******************************************************************************************************************
	 ***************************************** HELPER FUNCTIONS: *******************************************************
	 *******************************************************************************************************************/

	protected function moveOrCopyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName, $action) {
		$affected = [];
		$destinationFolderName = $this->normalizeFolderName($this->normalizeFolderName($targetFolderIdentifier) . $this->normalizeFolderName($newFolderName));
		$sourceFolderIdentifier = $this->normalizeFolderName($sourceFolderIdentifier);
		$blobs = $this->getBlobsFromIdentifier($sourceFolderIdentifier);
		foreach($blobs as $blob) {
			$newIdentifier = $destinationFolderName . substr($blob->getName(), strlen($sourceFolderIdentifier));
			$this->{$action}($blob->getName(), $newIdentifier);
			$affected[$blob->getName()] = $newIdentifier;
		}
		return $affected;
	}

	protected function normalizeFolderName($folderName) {
		if ($folderName === '.' || $folderName === '/' || trim($folderName, '/') === '') {
			return '';
		}
		return trim($folderName, '/') . '/';
	}

	protected function isFolder($fileIdentifier) {
		if(substr($fileIdentifier, -1) === '/') {
			return TRUE;
		}
		return FALSE;
	}

	protected function copy($sourceIdentifier, $targetIdentifier) {
		$this->blobRestProxy->copyBlob($this->container, $targetIdentifier, $this->container, $sourceIdentifier);
	}

	protected function move($sourceIdentifier, $destinationIdentifier) {
		$this->copy($sourceIdentifier, $destinationIdentifier);
		$this->blobRestProxy->deleteBlob($this->container, $sourceIdentifier);
	}


	protected function getBlobsFromIdentifier($sourceFolderIdentifier) {
		$blobs = [];
		$options = new AzureModels\ListBlobsOptions();
		$options->setPrefix($sourceFolderIdentifier);
		$blobListResult = $this->blobRestProxy->listBlobs($this->container, $options);
		if (is_object($blobListResult)) {
			$blobs = $blobListResult->getBlobs();
		}
		return $blobs;
	}

	protected function getBlob($fileIdentifier) {
		try {
			$blob = $this->blobRestProxy->getBlob($this->container, $fileIdentifier);
		} catch(ServiceException $e) {
			$flashMessage = $this->getFlashMessage($e);
			$this->getMessageQueueByIdentifier()->enqueue($flashMessage);
			return FALSE;
		}
		return $blob;
	}

	protected function constructConnectionString() {
		return 'DefaultEndpointsProtocol=' . $this->protocol . ';' .
			   'AccountName=' . $this->storageAccountName . ';' .
			   'AccountKey=' . $this->storageAccountKey;
	}

	protected function getFlashMessage($exception) {
		$flashMessage = $this->getFlashMessageService()->getFlashMessageInstance($exception->getMessage(), 'Error!', FlashMessage::ERROR);
		return $flashMessage;
	}

	protected function createContainer() {
		$createContainerOptions = new CreateContainerOptions();
		$createContainerOptions->setPublicAccess(PublicAccessType::BLOBS_ONLY);
		try {
			$this->blobRestProxy->createContainer($this->container, $createContainerOptions);
		} catch (ServiceException $e) {
			// Code 409 - "container already exists" is ok in this case
			if (!($e->getCode() == 409)) {
				$flashMessage = $this->getFlashMessage($e);
				$this->getMessageQueueByIdentifier()->enqueue($flashMessage);
			}
		}
	}

	protected function getConfigurationValue($option) {
		if(empty($this->configuration[$option])) {
			throw new \InvalidArgumentException('The required configuration setting "' . htmlspecialchars($option) . '" was not set!"');
		}
		return $this->configuration[$option];
	}

}
