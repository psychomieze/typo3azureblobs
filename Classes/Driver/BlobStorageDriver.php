<?php
namespace Neusta\AzureBlobs\Driver;

use MicrosoftAzure\Storage\Blob\Models\Blob;
use MicrosoftAzure\Storage\Blob\Models\CreateBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\CreateContainerOptions;
use MicrosoftAzure\Storage\Blob\Models\GetBlobResult;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Blob\Models\PublicAccessType;
use MicrosoftAzure\Storage\Common\ServiceException;
use MicrosoftAzure\Storage\Common\ServicesBuilder;
use Neusta\AzureBlobs\Exceptions\InvalidConfigurationException;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use Neusta\AzureBlobs\Service\FlashMessageService;

use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use MicrosoftAzure\Storage\Blob\BlobRestProxy;

/**
 * Class BlobStorageDriver
 * @package Neusta\AzureBlobs\Driver
 */
class BlobStorageDriver extends AbstractHierarchicalFilesystemDriver
{


    /**
     * @param string $identifier
     */
    public function dumpFileContents($identifier)
    {
        // TODO: Implement dumpFileContents() method.
    }

    /**
     * @var string
     */
    protected $container = 'mycontainer';
    /**
     * @var
     */
    protected $storageAccountKey;
    /**
     * @var string
     */
    protected $storageAccountName = 'typo3test123';
    /**
     * @var string
     */
    protected $protocol = 'https';
    /**
     * @var
     */
    protected $messageQueueByIdentifier;

    /**
     * @var \Neusta\AzureBlobs\Service\FLashMessageService
     */
    protected $flashMessageService;

    /**
     * @var BlobRestProxy
     */
    protected $blobRestProxy;


    /**
     * @return string
     */
    public function getContainer()
    {
        return $this->container;
    }


    /**
     * @return mixed
     */
    public function getStorageAccountKey()
    {
        return $this->storageAccountKey;
    }


    /**
     * @return string
     */
    public function getStorageAccountName()
    {
        return $this->storageAccountName;
    }

    /**
     * @return string
     */
    public function getProtocol()
    {
        return $this->protocol;
    }

    /**
     * @return \TYPO3\CMS\Core\Messaging\FlashMessageQueue
     */
    public function getMessageQueueByIdentifier()
    {
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
    public function setMessageQueueByIdentifier($messageQueueByIdentifier)
    {
        $this->messageQueueByIdentifier = $messageQueueByIdentifier;
    }

    /**
     * @return FlashMessageService
     */
    public function getFlashMessageService()
    {
        if (!is_object($this->flashMessageService)) {
            $this->flashMessageService = new FlashMessageService();
        }
        return $this->flashMessageService;
    }

    /**
     * @param mixed $flashMessageService
     */
    public function setFlashMessageService(FlashMessageService $flashMessageService)
    {
        $this->flashMessageService = $flashMessageService;
    }

    /**
     * @param $storageAccountName
     */
    public function setStorageAccountName($storageAccountName)
    {
        $this->storageAccountName = $storageAccountName;
    }

    /**
     * @param $protocol
     */
    public function setProtocol($protocol)
    {
        $this->protocol = $protocol;
    }


    /**
     * @param $container
     */
    public function setContainer($container)
    {
        $this->container = $container;
    }


    /*******************************************************************************************************************
     ***************************************** INTERFACE IMPLEMENTATION: ***********************************************
     *******************************************************************************************************************/

    /**
     * BlobStorageDriver constructor.
     * @param array $configuration
     */
    public function __construct(array $configuration = [])
    {
        parent::__construct($configuration);
    }

    /**
     * @param BlobRestProxy $blobRestProxy
     * @throws \TYPO3\CMS\Core\Exception
     */
    public function initialize(BlobRestProxy $blobRestProxy = null)
    {
        if ($blobRestProxy === null) {
            try {
                $this->blobRestProxy = ServicesBuilder::getInstance()->createBlobService($this->constructConnectionString());
            } catch (\Exception $e) {
                $flashMessage = $this->getFlashMessageService()->getFlashMessageInstance($e->getMessage(), 'Error!',
                    FlashMessage::ERROR);
                $this->getMessageQueueByIdentifier()->enqueue($flashMessage);
            }
        } else {
            $this->blobRestProxy = $blobRestProxy;
        }
        if ($this->processConfiguration()) {
            $this->createContainer();
            $this->capabilities = ResourceStorage::CAPABILITY_BROWSABLE | ResourceStorage::CAPABILITY_PUBLIC | ResourceStorage::CAPABILITY_WRITABLE;
        }
    }

    /**
     * @return bool
     */
    public function processConfiguration()
    {
        try {
            $this->container = $this->getConfigurationValue('containerName');
            $this->storageAccountName = $this->getConfigurationValue('accountName');
            $this->storageAccountKey = $this->getConfigurationValue('accountKey');
            $this->protocol = $this->getConfigurationValue('defaultEndpointsProtocol');
        } catch (InvalidConfigurationException $e) {
            $flashMessage = $this->getFlashMessageService()->getFlashMessageInstance($e->getMessage(),
                'Configuration Invalid!', FlashMessage::INFO);
            $this->getMessageQueueByIdentifier()->enqueue($flashMessage);
        }

        if ($this->container && $this->storageAccountKey && $this->storageAccountName && $this->protocol) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param $name
     * @param string $content
     * @param null $options
     */
    public function createObject($name, $content = '', $options = null)
    {
        if (!is_string($content)) {
            throw new \InvalidArgumentException('$content was not of type string');
        }
        if ($content === '') {
            // EOF
            $content = chr(26);
        }
        $this->blobRestProxy->createBlockBlob($this->container, $name, $content, $options);
    }

    /**
     * @param string $localFilePath
     * @param string $targetFolderIdentifier
     * @param string $newFileName
     * @param bool $removeOriginal
     * @return string
     */
    public function addFile($localFilePath, $targetFolderIdentifier, $newFileName = '', $removeOriginal = true)
    {
        if ($newFileName === '') {
            $newFileName = basename($localFilePath);
        }
        $targetFolderIdentifier = $this->normalizeFolderName($targetFolderIdentifier);
        $fileIdentifier = $targetFolderIdentifier . $newFileName;

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $contentType = finfo_file($fileInfo, $localFilePath);
        finfo_close($fileInfo);

        $options = new CreateBlobOptions();
        $options->setContentType($contentType);

        $this->createObject($fileIdentifier, file_get_contents($localFilePath), $options);
        return $fileIdentifier;
    }


    /**
     * @param string $newFolderName
     * @param string $parentFolderIdentifier
     * @param bool $recursive
     * @return void
     */
    public function createFolder($newFolderName, $parentFolderIdentifier = '', $recursive = true)
    {
        $parentFolderIdentifier = $this->normalizeFolderName($parentFolderIdentifier);
        $newFolderName = $this->normalizeFolderName($newFolderName);
        $newFolderIdentifier = $this->normalizeFolderName($parentFolderIdentifier . $newFolderName);
        $this->createObject($newFolderIdentifier);
    }

    /**
     * @param string $fileIdentifier
     * @return bool
     */
    public function fileExists($fileIdentifier)
    {
        if ($this->isFolder($fileIdentifier)) {
            return false;
        }
        return (bool)$this->getBlob($fileIdentifier);
    }

    /**
     * @param string $fileIdentifier
     * @return bool
     */
    public function deleteFile($fileIdentifier)
    {
        try {
            if ($this->fileExists($fileIdentifier)) {
                $this->blobRestProxy->deleteBlob($this->container, $fileIdentifier);
            }
            return true;
        } catch (ServiceException $e) {
            return false;
        }
    }

    /**
     * @param string $folderIdentifier
     * @param string $newName
     * @return string
     */
    public function renameFolder($folderIdentifier, $newName)
    {
        $newTargetParentFolderName = $this->normalizeFolderName(dirname($folderIdentifier));
        $newTargetFolderName = $this->normalizeFolderName($newName);
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        $this->moveFolderWithinStorage($folderIdentifier, $newTargetParentFolderName, $newTargetFolderName);
        return $newTargetParentFolderName . $newTargetFolderName;
    }

    /**
     * @param string $fileIdentifier
     * @return string
     */
    public function getFileContents($fileIdentifier)
    {
        $content = '';
        $blob = $this->getBlob($fileIdentifier);
        if ($blob !== false) {
            $content = stream_get_contents($blob->getContentStream());
        }
        return $content;
    }

    /**
     * @param string $fileIdentifier
     * @param string $contents
     * @return void
     */
    public function setFileContents($fileIdentifier, $contents)
    {
        $this->blobRestProxy->createBlockBlob($this->container, $fileIdentifier, $contents);
    }

    /**
     * @param string $fileName
     * @param string $folderIdentifier
     * @return bool
     */
    public function fileExistsInFolder($fileName, $folderIdentifier)
    {
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        $blob = $this->getBlob($folderIdentifier . $fileName);
        if ($blob) {
            return true;
        }
        return false;
    }

    /**
     * @param string $folderName
     * @param string $folderIdentifier
     * @return bool
     */
    public function folderExistsInFolder($folderName, $folderIdentifier)
    {
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        $folderName = $this->normalizeFolderName($folderName);
        $blob = $this->getBlob($this->normalizeFolderName($folderIdentifier . $folderName));
        if ($blob) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param string $fileIdentifier
     * @param bool $writable
     * @return string
     */
    public function getFileForLocalProcessing($fileIdentifier, $writable = true)
    {
        $temporaryPath = '';
        $blob = $this->getBlob($fileIdentifier);
        if ($blob !== false) {
            $source = stream_get_contents($blob->getContentStream());
            $temporaryPath = $this->getTemporaryPathForFile($fileIdentifier);
            $result = file_put_contents($temporaryPath, $source);
            if ($result === false) {
                throw new \RuntimeException('Writing file ' . $fileIdentifier . ' to temporary path failed.',
                    1320577649);
            }
        }
        return $temporaryPath;
    }

    /**
     * @param string $fileIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFileName
     * @return string
     */
    public function moveFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $newFileName)
    {
        $targetFolderIdentifier = $this->normalizeFolderName($targetFolderIdentifier);
        $targetName = $this->normalizeFolderName($targetFolderIdentifier) . $newFileName;
        $this->move($fileIdentifier, $targetName);
        return $targetName;
    }

    /**
     * @param string $sourceFolderIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFolderName
     * @return array
     */
    public function moveFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName)
    {
        return $this->moveOrCopyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName,
            'move');
    }

    /**
     * @param string $sourceFolderIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFolderName
     * @return array
     */
    public function copyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName)
    {
        return $this->moveOrCopyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName,
            'copy');
    }

    /**
     * @param string $folderIdentifier
     * @param string $identifier
     * @return bool
     */
    public function isWithin($folderIdentifier, $identifier)
    {
        if ($folderIdentifier === '') {
            return true;
        }
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        return GeneralUtility::isFirstPartOfStr($identifier, $folderIdentifier);
    }


    /**
     * @param string $folderIdentifier
     * @return bool
     */
    public function folderExists($folderIdentifier)
    {
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        if ($folderIdentifier === $this->normalizeFolderName($this->getRootLevelFolder())) {
            return true;
        }
        $blob = $this->getBlob($folderIdentifier);
        if ($blob) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param string $fileIdentifier
     * @param array $propertiesToExtract
     * @return array
     */
    public function getFileInfoByIdentifier($fileIdentifier, array $propertiesToExtract = array())
    {
        $fileInfo = [];
        if ($fileIdentifier === '') {
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

    /**
     * @param string $folderIdentifier
     * @return array
     */
    public function getFolderInfoByIdentifier($folderIdentifier)
    {
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        return $this->getFileInfoByIdentifier($folderIdentifier);
    }

    /**
     * @param string $folderIdentifier
     * @param int $start @TODO
     * @param int $numberOfItems @TODO
     * @param bool $recursive
     * @param array $filenameFilterCallbacks @TODO
     * @param string $sort @TODO
     * @param bool $sortRev @TODO
     * @return mixed
     */
    public function getFilesInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $filenameFilterCallbacks = [],
        $sort = '',
        $sortRev = false
    ) {
        $files = [];
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        $options = new ListBlobsOptions();
        $options->setPrefix($folderIdentifier);
        $blobListResult = $this->blobRestProxy->listBlobs($this->container, $options);
        if (is_object($blobListResult)) {
            /** @var Blob[] $blobs */
            $blobs = $blobListResult->getBlobs();
            foreach ($blobs as $blob) {
                $fileName = $blob->getName();
                if (substr($fileName, -1) === '/') {
                    // folder
                    continue;
                }
                if ($recursive === false && substr_count($fileName, '/') > substr_count($folderIdentifier, '/')) {
                    // in sub-folders
                    continue;
                }
                $files[$fileName] = $fileName;
            }
        }
        return $files;
    }

    /**
     * @param string $folderIdentifier
     * @return bool
     */
    public function isFolderEmpty($folderIdentifier)
    {
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        $options = new ListBlobsOptions();
        $options->setPrefix($folderIdentifier);
        $blobListResult = $this->blobRestProxy->listBlobs($this->container, $options);
        $blobs = $blobListResult->getBlobs();
        return (count($blobs) === 0);
    }

    /**
     * @param string $fileName
     * @param string $parentFolderIdentifier
     * @return string
     */
    public function createFile($fileName, $parentFolderIdentifier)
    {
        $parentFolderIdentifier = $this->normalizeFolderName($parentFolderIdentifier);
        $newIdentifier = $parentFolderIdentifier . $fileName;
        $this->createObject($newIdentifier, chr(26));
        return $newIdentifier;
    }

    /**
     * @param string $fileIdentifier
     * @param string $targetFolderIdentifier
     * @param string $fileName
     * @return string
     */
    public function copyFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $fileName)
    {
        $targetFolderIdentifier = $this->normalizeFolderName($targetFolderIdentifier);
        $targetFileName = $targetFolderIdentifier . $fileName;
        $this->copy($fileIdentifier, $targetFileName);
        return $targetFileName;
    }

    /**
     * @param string $fileIdentifier
     * @param string $newName
     * @return string
     */
    public function renameFile($fileIdentifier, $newName)
    {
        $targetFolder = $this->normalizeFolderName(dirname($fileIdentifier));
        $this->moveFileWithinStorage($fileIdentifier, $targetFolder, $newName);
        return $targetFolder . $newName;
    }

    /**
     * @param string $fileIdentifier
     * @param string $localFilePath
     * @return void
     */
    public function replaceFile($fileIdentifier, $localFilePath)
    {
        $targetFolder = $this->normalizeFolderName(dirname($fileIdentifier));
        $newName = basename($fileIdentifier);
        $this->addFile($localFilePath, $targetFolder, $newName);
    }

    /**
     * @param string $identifier
     * @return array
     */
    public function getPermissions($identifier)
    {
        return ['r' => true, 'w' => true];
    }

    /**
     * @param string $folderIdentifier
     * @param int $start
     * @param int $numberOfItems
     * @param bool $recursive
     * @param array $folderNameFilterCallbacks
     * @param string $sort
     * @param bool $sortRev
     * @return array
     */
    public function getFoldersInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $folderNameFilterCallbacks = [],
        $sort = '',
        $sortRev = false
    ) {
        $folderNames = [];
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        $options = new ListBlobsOptions();
        $options->setPrefix($folderIdentifier);
        $blobListResult = $this->blobRestProxy->listBlobs($this->container, $options);
        if (is_object($blobListResult)) {
            $blobs = $blobListResult->getBlobs();
            /** @var Blob[] $blobs */
            foreach ($blobs as $blob) {
                $blobName = $blob->getName();
                if ($blobName === $folderIdentifier) {
                    continue;
                }
                if (substr($blobName, -1) === '/') {
                    if ($recursive === false && $this->isSubSubFolder($blobName, $folderIdentifier)) {
                        continue;
                    }
                    $folderNames[$blobName] = $blobName;
                }
            }
        }
        return $folderNames;
    }

    /**
     * @param string $fileIdentifier
     * @param string $hashAlgorithm
     * @return string
     */
    public function hash($fileIdentifier, $hashAlgorithm)
    {
        return sha1($fileIdentifier);
    }

    /**
     * @param string $identifier
     * @return string
     */
    public function hashIdentifier($identifier)
    {
        return sha1($identifier);
    }

    /**
     * @param string $identifier
     * @return string
     */
    public function getPublicUrl($identifier)
    {
        return $this->protocol . '://' . $this->storageAccountName . '.blob.core.windows.net/' . $this->container . '/' . $identifier;
    }


    /**
     * @return string
     */
    public function getRootLevelFolder()
    {
        return '';
    }

    /**
     * @param int $capabilities
     * @return int
     */
    public function mergeConfigurationCapabilities($capabilities)
    {
        $this->capabilities &= $capabilities;
        return $this->capabilities;
    }

    /**
     * @return string
     */
    public function getDefaultFolder()
    {
        return $this->getRootLevelFolder();
    }

    /**
     * @param string $folderIdentifier
     * @param bool $deleteRecursively
     * @return void
     */
    public function deleteFolder($folderIdentifier, $deleteRecursively = true)
    {
        $sourceFolderIdentifier = $this->normalizeFolderName($folderIdentifier);
        $blobs = $this->getBlobsFromFolder($sourceFolderIdentifier);
        foreach ($blobs as $blob) {
            $this->blobRestProxy->deleteBlob($this->container, $blob->getName());
        }
    }


    /*******************************************************************************************************************
     ***************************************** HELPER FUNCTIONS: *******************************************************
     *******************************************************************************************************************/

    /**
     * @param string $sourceFolderIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFolderName
     * @param string $action "move" or "copy"
     * @return array
     */
    protected function moveOrCopyFolderWithinStorage(
        $sourceFolderIdentifier,
        $targetFolderIdentifier,
        $newFolderName,
        $action
    ) {
        $affected = [];
        $destinationFolderName = $this->normalizeFolderName($this->normalizeFolderName($targetFolderIdentifier) . $this->normalizeFolderName($newFolderName));
        $sourceFolderIdentifier = $this->normalizeFolderName($sourceFolderIdentifier);
        $blobs = $this->getBlobsFromFolder($sourceFolderIdentifier);
        foreach ($blobs as $blob) {
            $newIdentifier = $destinationFolderName . substr($blob->getName(), strlen($sourceFolderIdentifier));
            $this->{$action}($blob->getName(), $newIdentifier);
            $affected[$blob->getName()] = $newIdentifier;
        }
        return $affected;
    }

    /**
     * @param string $folderName
     * @return string
     */
    protected function normalizeFolderName($folderName)
    {
        $folderName = trim($folderName, '/');
        if ($folderName === '.' || $folderName === '') {
            return '';
        }
        return $folderName . '/';
    }

    /**
     * @param string $fileIdentifier
     * @return bool
     */
    protected function isFolder($fileIdentifier)
    {
        return (substr($fileIdentifier, -1) === '/');
    }

    /**
     * @param string $sourceIdentifier
     * @param string $targetIdentifier
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function copy($sourceIdentifier, $targetIdentifier)
    {
        try {
            $this->blobRestProxy->copyBlob($this->container, $targetIdentifier, $this->container, $sourceIdentifier);
        } catch (ServiceException $e) {
            $flashMessage = $this->getFlashMessage($e);
            $this->getMessageQueueByIdentifier()->enqueue($flashMessage);
        }
    }

    /**
     * @param string $sourceIdentifier
     * @param string $destinationIdentifier
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function move($sourceIdentifier, $destinationIdentifier)
    {
        $this->copy($sourceIdentifier, $destinationIdentifier);
        try {
            $this->blobRestProxy->deleteBlob($this->container, $sourceIdentifier);
        } catch (ServiceException $e) {
            $flashMessage = $this->getFlashMessage($e);
            $this->getMessageQueueByIdentifier()->enqueue($flashMessage);
        }
    }


    /**
     * @param string $sourceFolderIdentifier
     * @return array
     */
    protected function getBlobsFromFolder($sourceFolderIdentifier)
    {
        $blobs = [];
        $options = new ListBlobsOptions();
        $options->setPrefix($sourceFolderIdentifier);
        $blobListResult = $this->blobRestProxy->listBlobs($this->container, $options);
        if (is_object($blobListResult)) {
            $blobs = $blobListResult->getBlobs();
        }
        return $blobs;
    }

    /**
     * @param string $fileIdentifier
     * @return bool|GetBlobResult
     * @TODO removed error handling as 404 is ok (check file existence before creation) and currently can't be differentiated
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function getBlob($fileIdentifier)
    {
        try {
            $blob = $this->blobRestProxy->getBlob($this->container, $fileIdentifier);
        } catch (ServiceException $e) {
            return false;
        }
        return $blob;
    }

    /**
     * @return string
     */
    protected function constructConnectionString()
    {
        return 'DefaultEndpointsProtocol=' . $this->protocol . ';' .
        'AccountName=' . $this->storageAccountName . ';' .
        'AccountKey=' . $this->storageAccountKey;
    }

    /**
     * @param \Exception $exception
     * @param int $errorLevel
     * @return FlashMessage
     */
    protected function getFlashMessage($exception, $errorLevel = FlashMessage::ERROR)
    {
        $flashMessage = $this->getFlashMessageService()->getFlashMessageInstance('Message:' . $exception->getMessage() .
            'AdditionalInformation:' . $exception->getFile() . ' ' . $exception->getLine(), 'Error!', $errorLevel);
        return $flashMessage;
    }

    /**
     * @return void
     */
    protected function createContainer()
    {
        $createContainerOptions = new CreateContainerOptions();
        $createContainerOptions->setPublicAccess(PublicAccessType::BLOBS_ONLY);
        try {
            $this->blobRestProxy->createContainer($this->container, $createContainerOptions);
        } catch (ServiceException $e) {
            // Code 409 - "container already exists" is ok in this case
            if (!($e->getCode() === 409)) {
                $flashMessage = $this->getFlashMessage($e);
                $this->getMessageQueueByIdentifier()->enqueue($flashMessage);
            }
        }
    }

    /**
     * @param string $option
     * @return mixed
     * @throws InvalidConfigurationException
     */
    protected function getConfigurationValue($option)
    {
        if (empty($this->configuration[$option])) {
            throw new InvalidConfigurationException('The required configuration setting "' . htmlspecialchars($option) . '" was not set!"');
        }
        return $this->configuration[$option];
    }

    /**
     * Checks whether a folder is a sub-sub folder from another folder
     *
     * @param string $folderToCheck
     * @param string $parentFolderIdentifier
     * @return bool
     */
    protected function isSubSubFolder($folderToCheck, $parentFolderIdentifier)
    {
        return substr_count($folderToCheck, '/') > substr_count($parentFolderIdentifier, '/') + 1;
    }

    /**
     * Returns the identifier of a file inside the folder
     *
     * @param string $fileName
     * @param string $folderIdentifier
     * @return string file identifier
     */
    public function getFileInFolder($fileName, $folderIdentifier)
    {
        return $this->normalizeFolderName($folderIdentifier) . $fileName;
    }

    /**
     * Returns the identifier of a folder inside the folder
     *
     * @param string $folderName The name of the target folder
     * @param string $folderIdentifier
     * @return string folder identifier
     */
    public function getFolderInFolder($folderName, $folderIdentifier)
    {
        return $this->normalizeFolderName($this->normalizeFolderName($folderIdentifier) . $folderName);
    }

    /**
     * Returns the number of files inside the specified path
     *
     * @param string $folderIdentifier
     * @param bool $recursive
     * @param array $filenameFilterCallbacks callbacks for filtering the items
     * @return int Number of files in folder
     */
    public function countFilesInFolder($folderIdentifier, $recursive = false, array $filenameFilterCallbacks = array())
    {
        // TODO: Implement countFilesInFolder() method.
    }

    /**
     * Returns the number of folders inside the specified path
     *
     * @param string $folderIdentifier
     * @param bool $recursive
     * @param array $folderNameFilterCallbacks callbacks for filtering the items
     * @return int Number of folders in folder
     */
    public function countFoldersInFolder(
        $folderIdentifier,
        $recursive = false,
        array $folderNameFilterCallbacks = array()
    ) {
        // TODO: Implement countFoldersInFolder() method.
    }

    /**
     * Returns the identifier of the folder the file resides in
     *
     * @todo beautify
     * @param string $fileIdentifier
     * @return mixed
     */
    public function getParentFolderIdentifierOfIdentifier($fileIdentifier)
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        return str_replace('\\', '/', dirname($fileIdentifier));
    }
}
