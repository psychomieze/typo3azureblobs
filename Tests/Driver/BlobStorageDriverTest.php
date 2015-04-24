<?php
namespace Neusta\AzureBlobs\Tests\Driver;

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\Blob;
use MicrosoftAzure\Storage\Blob\Models\BlobProperties;
use MicrosoftAzure\Storage\Blob\Models\ContainerProperties;
use MicrosoftAzure\Storage\Blob\Models\CreateBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\GetBlobResult;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsResult;
use MicrosoftAzure\Storage\Common\ServiceException;
use Neusta\AzureBlobs\Driver\BlobStorageDriver;

use Neusta\AzureBlobs\Service\FlashMessageService;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamWrapper;

use Prophecy\Argument as Arg;
use Prophecy\Prophecy\ObjectProphecy;
use Prophecy\Prophet;

use TYPO3\CMS\Core\Messaging\FlashMessageQueue;


class BlobStorageDriverTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var FlashMessageQueue|ObjectProphecy
     */
    protected $flashMessageQueue;
    /**
     * @var FlashMessageService|ObjectProphecy
     */
    protected $flashMessageService;

    /**
     * @var ObjectProphecy|BlobRestProxy
     */
    private $blobRestProxy;

    /**
     * @var BlobStorageDriver
     */
    private $storageDriver;

    private $testFile;
    private $emptyTestFile;
    private $emptyTestFilePath;
    private $testFilePath;
    private $testContent = 'this is test content.';


    public function __construct()
    {
        // enable auto complete for prophecies in methods
        $this->blobRestProxy = $this->prophesize(BlobRestProxy::class);
        $this->flashMessageService = $this->prophesize(FlashMessageService::class);
        $this->flashMessageQueue = $this->prophesize(FlashMessageQueue::class);
        parent::__construct();
    }

    public function setUp()
    {
        $this->blobRestProxy = $this->prophesize(BlobRestProxy::class);
        $this->flashMessageQueue = $this->prophesize(FlashMessageQueue::class);
        $this->flashMessageService = $this->prophesize(FlashMessageService::class);
        $this->storageDriver = new BlobStorageDriver([
            'containerName' => 'mycontainer',
            'accountKey' => '123',
            'accountName' => 'foo',
            'defaultEndpointsProtocol' => 'http'
        ]);
        $this->storageDriver->setMessageQueueByIdentifier($this->flashMessageQueue->reveal());
        $this->storageDriver->setFlashMessageService($this->flashMessageService->reveal());
        $this->storageDriver->initialize($this->blobRestProxy->reveal());

        // file stuff
        vfsStreamWrapper::register();
        $root = new vfsStreamDirectory('exampleDir');
        vfsStreamWrapper::setRoot($root);
        $this->testFile = vfsStream::newFile('test.txt')->at($root)->setContent($this->testContent);
        $this->emptyTestFile = vfsStream::newFile('test2.txt')->at($root)->setContent('');
        $this->testFilePath = vfsStream::url('exampleDir/test.txt');
        $this->emptyTestFilePath = vfsStream::url('exampleDir/test2.txt');

    }

    /**
     * @return void
     */
    public function testCreateObjectCallsCreateBlockBlob()
    {
        $this->storageDriver->createObject('foo/bar');
        $this->blobRestProxy->createBlockBlob('mycontainer', 'foo/bar', chr(26), Arg::any())->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testCreateObjectUsesConfiguredContainer()
    {
 

        $this->storageDriver->setContainer('foo');
        $this->storageDriver->createObject('bar/baz.txt');
        $this->blobRestProxy->createBlockBlob('foo', 'bar/baz.txt', chr(26), Arg::any())->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testCreateObjectAddsContentToBlob()
    {
 

        $this->storageDriver->createObject('foo/bar.txt', 'test content');
        $this->blobRestProxy->createBlockBlob('mycontainer', 'foo/bar.txt', 'test content',
            Arg::any())->shouldHaveBeenCalled();
    }

    /**
     * @return void
     * @throws \PHPUnit_Framework_Exception
     */
    public function testCreateObjectThrowsExceptionIfContentIsNoString()
    {

        $this->expectException(\InvalidArgumentException::class);
        $this->storageDriver->createObject('foo/bar.txt', ['foo' => 'bar']);
    }

    /**
     * @return void
     */
    public function testAddFileSetsFilenameToBasenameIfNotGiven()
    {
 

        $this->storageDriver->addFile($this->testFilePath, 'fileadmin/test/');
        $this->blobRestProxy->createBlockBlob('mycontainer', 'fileadmin/test/test.txt', Arg::any(),
            Arg::any())->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testAddFileSetsFilenameToGivenFilename()
    {
 

        $this->storageDriver->addFile($this->testFilePath, 'fileadmin/test', 'foo.txt');
        $this->blobRestProxy->createBlockBlob('mycontainer', 'fileadmin/test/foo.txt', Arg::any(),
            Arg::any())->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testAddFileSetsMimeTypeInfo()
    {
 

        $this->storageDriver->addFile($this->testFilePath, 'fileadmin/test');
        $options = new CreateBlobOptions;
        $options->setContentType('text/plain');
        $this->blobRestProxy->createBlockBlob('mycontainer', 'fileadmin/test/test.txt', Arg::any(),
            Arg::exact($options))->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testAddFileSetsContent()
    {
 

        $this->storageDriver->addFile($this->testFilePath, 'fileadmin/test');
        $this->blobRestProxy->createBlockBlob('mycontainer', 'fileadmin/test/test.txt', $this->testContent,
            Arg::any())->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testAddFileReturnsTargetIdentifier()
    {
        $targetIdentifier = $this->storageDriver->addFile($this->testFilePath, 'fileadmin/test');
        static::assertSame('fileadmin/test/test.txt', $targetIdentifier);
    }

    /**
     * @return void
     */
    public function testAddFolderCreatesRootLevelFolderIfNoParentFolderGiven()
    {
 

        $this->storageDriver->createFolder('foo');
        $this->blobRestProxy->createBlockBlob(Arg::any(), 'foo/', Arg::cetera())->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testAddFolderCreatesFolderWithParentPrefixed()
    {
 

        $this->storageDriver->createFolder('foo', 'bar');
        $this->blobRestProxy->createBlockBlob(Arg::any(), 'bar/foo/', Arg::cetera())->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testFileExistsReturnsFalseIfFolderNameGiven()
    {
 

        $return = $this->storageDriver->fileExists('foo/');
        static::assertFalse($return);
    }

    /**
     * @return void
     */
    public function testFileExistsReturnsFalseIfWebserviceThrowsException()
    {
 

        $this->blobRestProxy->getBlob(Arg::any(), Arg::cetera())->will(function () {
            throw new ServiceException(123);
        });
        $result = $this->storageDriver->fileExists('bar.txt');
        static::assertFalse($result);
    }

    /**
     * @return void
     */
    public function testFileExistsReturnsTrueIfWebserviceDoesNotThrowException()
    {
 

        $this->blobRestProxy->getBlob(Arg::any(), Arg::cetera())->willReturn(true);
        $result = $this->storageDriver->fileExists('bar.txt');
        static::assertTrue($result);
    }

    /**
     * @return void
     */
    public function testDeleteFileCallsGetBlobToCheckForFileExistence()
    {
 

        $this->blobRestProxy->getBlob(Arg::any(), 'test.txt')->willReturn(true);
        $this->blobRestProxy->deleteBlob(Arg::any(), 'test.txt')->willReturn(false);
        $this->storageDriver->deleteFile('test.txt');
        $this->blobRestProxy->getBlob(Arg::any(), 'test.txt')->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testDeleteFileOnlyDeletesFileIfFileExists()
    {
 

        $this->blobRestProxy->getBlob(Arg::any(), 'test.txt')->willReturn(true);
        $this->blobRestProxy->deleteBlob(Arg::any(), 'test.txt')->willReturn(false);
        $this->storageDriver->deleteFile('test.txt');
        $this->blobRestProxy->deleteBlob(Arg::any(), 'test.txt')->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testDeleteFileDoesNotCallDeleteForNonExistingFile()
    {
 

        $this->blobRestProxy->getBlob(Arg::any(), 'test.txt')->willReturn(false);
        $this->storageDriver->deleteFile('test.txt');
        $this->blobRestProxy->deleteBlob(Arg::any(), Arg::cetera())->shouldNotHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testDeleteFileReturnsFalseIfExceptionIsThrown()
    {
 

        $this->blobRestProxy->getBlob(Arg::any(), 'test.txt')->willReturn(true);
        $this->blobRestProxy->deleteBlob(Arg::any(), Arg::cetera())->will(function () {
            throw new ServiceException(123);
        });
        $result = $this->storageDriver->deleteFile('test.txt');
        static::assertFalse($result);
    }

    /**
     * @return void
     */
    public function testDeleteFileReturnsTrueIfNoExceptionIsThrown()
    {
 

        $this->blobRestProxy->getBlob(Arg::any(), 'test.txt')->willReturn(true);
        $this->blobRestProxy->deleteBlob(Arg::any(), 'test.txt')->willReturn(true);
        $result = $this->storageDriver->deleteFile('test.txt');
        static::assertTrue($result);
    }

    /**
     * @return void
     */
    public function testMoveFileWithinStorageCopyAndDeletesFile()
    {
 

        $this->storageDriver->moveFileWithinStorage('foo.txt', 'bar', 'baz.txt');
        $this->blobRestProxy->copyBlob(Arg::any(), 'bar/baz.txt', Arg::any(), 'foo.txt')->shouldHaveBeenCalled();
        $this->blobRestProxy->deleteBlob(Arg::any(), 'foo.txt')->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testMoveFileWithinStorageUsesConfiguredContainer()
    {
 

        $this->storageDriver->setContainer('foo');
        $this->storageDriver->moveFileWithinStorage('test.txt', 'test', 'bar.txt');
        $this->blobRestProxy->copyBlob('foo', Arg::any(), 'foo', Arg::any())->shouldHaveBeenCalled();
        $this->blobRestProxy->deleteBlob('foo', Arg::cetera())->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testMoveFileWithinStorageReturnsNewFilename()
    {
        $result = $this->storageDriver->moveFileWithinStorage('test.txt', 'bar', 'baz.txt');
        static::assertSame('bar/baz.txt', $result);
    }

    /**
     * @return void
     */
    public function testMoveFolderWithinStorageFetchesAllElementsInFolder()
    {
 

        $this->storageDriver->setContainer('foo');
        $this->storageDriver->moveFolderWithinStorage('bar', 'foo', 'hans');
        $options = new ListBlobsOptions();
        $options->setPrefix('bar/');
        $this->blobRestProxy->listBlobs('foo', $options)->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testMoveFolderWithinStorageCallsDeleteAndCopyForEachFileWithin()
    {
 

        $this->moveFolderMethodStubs();

        $this->storageDriver->moveFolderWithinStorage('bar', 'foo', 'hans');
        $this->blobRestProxy->copyBlob(Arg::any(), 'foo/hans/test.txt', Arg::any(),
            'bar/test.txt')->shouldHaveBeenCalled();
        $this->blobRestProxy->deleteBlob(Arg::any(), 'bar/test.txt')->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testMoveFolderWithinStorageReturnsAffectedFilesArray()
    {
 

        $this->moveFolderMethodStubs();

        $result = $this->storageDriver->moveFolderWithinStorage('bar', 'foo', 'hans');
        static::assertSame([
            'bar/test.txt' => 'foo/hans/test.txt'
        ], $result);
    }

    protected function moveFolderMethodStubs()
    {
 

        $this->blobRestProxy->listBlobs(Arg::cetera())->will(function () {
            $prophet = new Prophet();
            $listBlobsResult = $prophet->prophesize(ListBlobsResult::class);
            $listBlobsResult->getBlobs()->will(function () {
                $prophet = new Prophet();
                $getBlobResult = $prophet->prophesize(Blob::class);
                $getBlobResult->getName()->will(function () {
                    return 'bar/test.txt';
                });
                return [$getBlobResult->reveal()];
            });
            return $listBlobsResult->reveal();
        });
        $this->blobRestProxy->copyBlob(Arg::any(), Arg::any(), Arg::any(), Arg::cetera())->willReturn(true);
        $this->blobRestProxy->deleteBlob(Arg::any(), Arg::cetera())->willReturn(true);
    }

    /**
     * @return void
     */
    public function testCopyFolderWithinStorage()
    {
 

        $this->moveFolderMethodStubs();

        $this->storageDriver->copyFolderWithinStorage('bar', 'foo', 'karla');
        $this->blobRestProxy->copyBlob(Arg::any(), 'foo/karla/test.txt', Arg::any(),
            'bar/test.txt')->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testRenameFolder()
    {
 

        $this->moveFolderMethodStubs();
        $this->storageDriver->renameFolder('bar/', 'bom/');
        $this->blobRestProxy->copyBlob(Arg::any(), 'bom/test.txt', Arg::any(), 'bar/test.txt')->shouldHaveBeenCalled();
        $this->blobRestProxy->deleteBlob(Arg::any(), 'bar/test.txt')->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testGetFileContentsReturnsContent()
    {
 

        $blobProphecy = $this->prophesize(GetBlobResult::class);
        $blobProphecy->getContentStream()->willReturn(fopen($this->testFilePath, 'r'));
        $this->blobRestProxy->getBlob(Arg::any(), Arg::cetera())->willReturn($blobProphecy->reveal());
        $result = $this->storageDriver->getFileContents('test.txt');
        static::assertSame($this->testContent, $result);
    }

    /**
     * @return void
     */
    public function testGetFileContentsReturnsEmptyStringIfFileDoesNotExist()
    {
 

        $this->blobRestProxy->getBlob(Arg::any(), 'test.txt')->willReturn(false);
        $result = $this->storageDriver->getFileContents('test.txt');
        static::assertSame('', $result);
    }

    /**
     * @return void
     */
    public function testDeleteFolderCallsDeleteForFilesInFolder()
    {
 

        $this->moveFolderMethodStubs();
        $this->storageDriver->deleteFolder('bar/');
        $this->blobRestProxy->deleteBlob(Arg::any(), 'bar/test.txt')->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testDeleteFolderFetchesAllFilesInFolderToDelete()
    {
 

        $this->moveFolderMethodStubs();
        $options = new ListBlobsOptions();
        $options->setPrefix('bar/');
        $this->storageDriver->deleteFolder('bar/');
        $this->blobRestProxy->listBlobs(Arg::any(), $options)->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testSetFileContents()
    {
 

        $this->storageDriver->setFileContents('foo/bar/baz.txt', 'hallo welt.');
        $this->blobRestProxy->createBlockBlob(Arg::any(), 'foo/bar/baz.txt', 'hallo welt.');
    }

    /**
     * @return void
     */
    public function testFileExistsInFolderReturnsFalseIfNonExistingFileGiven()
    {
        $return = $this->storageDriver->fileExistsInFolder('test.txt', 'bar');
        static::assertFalse($return);
    }

    /**
     * @return void
     */
    public function testFileExistsInFolderReturnsTrueIfFileExists()
    {
 

        $this->blobRestProxy->getBlob(Arg::any(), 'bar/test.txt')->willReturn(true);
        $return = $this->storageDriver->fileExistsInFolder('test.txt', 'bar');
        $this->blobRestProxy->getBlob(Arg::any(), 'bar/test.txt')->shouldHaveBeenCalled();
        static::assertTrue($return);
    }

    /**
     * @return void
     */
    public function testFolderExistsInFolderReturnsFalseIfFolderDoesNotExist()
    {
        $result = $this->storageDriver->folderExistsInFolder('foo', 'bar');
        static::assertFalse($result);
    }

    /**
     * @return void
     */
    public function testFolderExistsInFolderReturnsTrueIfFolderExists()
    {
 

        $this->blobRestProxy->getBlob(Arg::any(), 'foo/bar/')->willReturn(true);
        $result = $this->storageDriver->folderExistsInFolder('bar', 'foo');
        $this->blobRestProxy->getBlob(Arg::any(), 'foo/bar/')->shouldHaveBeenCalled();
        static::assertTrue($result);
    }

    /**
     * @return void
     */
    public function testGetFileForLocalProcessingWritesToFile()
    {
 

        $blobProphecy = $this->prophesize(GetBlobResult::class);
        $blobProphecy->getContentStream()->willReturn(fopen($this->testFilePath, 'r'));
        $messageQueue = $this->prophesize(FlashMessageQueue::class);
        $this->blobRestProxy->getBlob(Arg::any(), Arg::cetera())->willReturn($blobProphecy->reveal());
        $storageMock = $this
            ->getMockBuilder('Neusta\AzureBlobs\Driver\BlobStorageDriver')
            ->setMethods(['getTemporaryPathForFile', 'createContainer'])
            ->setConstructorArgs([
                [
                    'containerName' => 'mycontainer',
                    'accountKey' => '123',
                    'accountName' => 'foo',
                    'defaultEndpointsProtocol' => 'http'
                ]
            ])
            ->getMock();
        $storageMock->setMessageQueueByIdentifier($messageQueue);
        $storageMock->initialize($this->blobRestProxy->reveal());
        $storageMock->expects(static::any())->method('getTemporaryPathForFile')->will(static::returnValue(vfsStream::url('exampleDir/test2.txt')));
        $return = $storageMock->getFileForLocalProcessing('bar/test.txt');
        static::assertSame($this->testContent, stream_get_contents(fopen($return, 'r')));
    }

    /**
     * @return void
     */
    public function testGetFileForLocalProcessingReturnsEmptyStringIfFileDoesNotExist()
    {
 

        $this->blobRestProxy->getBlob(Arg::any(), 'test.txt')->willReturn(false);
        $result = $this->storageDriver->getFileForLocalProcessing('test.txt');
        static::assertSame('', $result);
    }

    /**
     * @return void
     */
    public function testIsWithinReturnsTrueIfSubfolderPathIsTheSame()
    {
        $result = $this->storageDriver->isWithin('foo/bar/', 'foo/bar/baz.txt');
        static::assertTrue($result);
    }

    /**
     * @return void
     */
    public function testIsWithinReturnsTrueIfFileOnRootIsGiven()
    {
        $result = $this->storageDriver->isWithin('', 'baz.txt');
        static::assertTrue($result);
    }

    /**
     * @return void
     */
    public function testIsWithinReturnsTrueIfSameIdentifierIsGiven()
    {
        $result = $this->storageDriver->isWithin('foo/', 'foo/');
        static::assertTrue($result);
    }

    /**
     * @return void
     */
    public function testIsWithinReturnsFalseIfFileIsNotInFolder()
    {
        $result = $this->storageDriver->isWithin('foo/', 'baz.txt');
        static::assertFalse($result);
    }

    /**
     * @return void
     */
    public function testIsWithinReturnsFalseIfFileIsInSameSubfolderStructureButAsSisterElement()
    {
        $result = $this->storageDriver->isWithin('foo/bar', 'foo/baz/test.txt');
        static::assertFalse($result);
    }

    /**
     * @return void
     */
    public function testFolderExistsReturnsTrueIfBlobWithFolderNameExists()
    {
 

        $this->blobRestProxy->getBlob(Arg::any(), 'foo/')->willReturn(true);
        $result = $this->storageDriver->folderExists('foo');
        $this->blobRestProxy->getBlob(Arg::any(), 'foo/')->shouldHaveBeenCalled();
        static::assertTrue($result);
    }

    /**
     * @return void
     */
    public function testFolderExistsReturnsTrueIfRootFolder()
    {
        $result = $this->storageDriver->folderExists('/');
        static::assertTrue($result);
    }

    /**
     * @return void
     */
    public function testFolderExistsReturnsFalseIfBlobWithFolderNameDoesNotExist()
    {
 

        $this->blobRestProxy->getBlob(Arg::any(), 'bar/')->willReturn(false);
        $result = $this->storageDriver->folderExists('bar');
        $this->blobRestProxy->getBlob(Arg::any(), 'bar/')->shouldHaveBeenCalled();
        static::assertFalse($result);
    }

    /**
     * @return void
     */
    public function testGetFileInfoByIdentifierReturnsArrayWithBasicInformation()
    {
        $this->getFileOrFolderInfoMethodMocks('foo/test.txt');
        $this->storageDriver->setStorageUid(1);
        $result = $this->storageDriver->getFileInfoByIdentifier('foo/test.txt');
        static::assertSame('foo/test.txt', $result['identifier']);
        static::assertSame('test.txt', $result['name']);
        static::assertSame(1, $result['storage']);
    }

    /**
     * @return void
     */
    public function testGetFolderInfoByIdentifierReturnsArrayWithBasicInformation()
    {
        $this->getFileOrFolderInfoMethodMocks('foo/');
        $this->storageDriver->setStorageUid(1);
        $result = $this->storageDriver->getFolderInfoByIdentifier('foo/');
        static::assertSame('foo/', $result['identifier']);
        static::assertSame('foo', $result['name']);
        static::assertSame(1, $result['storage']);
    }

    protected function getFileOrFolderInfoMethodMocks($fileOrFolder)
    {
 
        $blobPropertiesProphecy = $this->prophesize(BlobProperties::class);
        $blobPropertiesProphecy->getLastModified()->willReturn(new \DateTime());
        $blobPropertiesProphecy->getContentLength()->willReturn(new \DateTime());
        $blobProperties = $blobPropertiesProphecy->reveal();

        $blobProphecy = $this->prophesize(GetBlobResult::class);
        $blobProphecy->getProperties()->willReturn($blobProperties);
        $this->blobRestProxy->getBlob(Arg::any(), $fileOrFolder)->willReturn($blobProphecy->reveal());
    }

    /**
     * @return void
     */
    public function testGetFilesInFolderSetsPrefix()
    {
 

        $this->filesInFolderMethodStubs();
        $options = new ListBlobsOptions();
        $options->setPrefix('foo/');
        $this->storageDriver->getFilesInFolder('foo');
        $this->blobRestProxy->listBlobs(Arg::any(), $options)->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testGetFilesInFolderReturnsFilesInFolder()
    {
        $this->filesInFolderMethodStubs();
        $expectedFiles = [
            'bar/test.txt' => 'bar/test.txt',
            'bar/test2.txt' => 'bar/test2.txt'
        ];
        $returnedFiles = $this->storageDriver->getFilesInFolder('bar');
        static::assertSame($expectedFiles, $returnedFiles);
    }

    /**
     * @return void
     */
    public function testGetFilesInFolderReturnsEmptyArrayIfNoFilesFound()
    {
        $result = $this->storageDriver->getFilesInFolder('aoeuhsn');
        static::assertSame([], $result);
    }

    /**
     * @return void
     */
    public function testGetFilesInFolderReturnsFilesInSubfoldersIfRecursiveSetToTrue()
    {
        $this->filesInFolderMethodStubs();
        $expectedFiles = [
            'bar/test.txt' => 'bar/test.txt',
            'bar/test2.txt' => 'bar/test2.txt',
            'bar/foo/test2.txt' => 'bar/foo/test2.txt'
        ];
        $returnedFiles = $this->storageDriver->getFilesInFolder('bar', 0, 0, true);
        static::assertSame($expectedFiles, $returnedFiles);
    }

    protected function filesInFolderMethodStubs()
    {
 

        $this->blobRestProxy->listBlobs(Arg::cetera())->will(function () {
            $prophet = new Prophet();
            $listBlobsResult = $prophet->prophesize(ListBlobsResult::class);
            $listBlobsResult->getBlobs()->will(function () {
                $prophet = new Prophet();
                $getBlobResult = $prophet->prophesize(Blob::class);
                $getBlobResult->getName()->will(function () {
                    return 'bar/test.txt';
                });
                $getBlobResult2 = $prophet->prophesize(Blob::class);
                $getBlobResult2->getName()->will(function () {
                    return 'bar/test2.txt';
                });
                $getBlobResult3 = $prophet->prophesize(Blob::class);
                $getBlobResult3->getName()->will(function () {
                    return 'bar/foo/test2.txt';
                });
                $getBlobResult4 = $prophet->prophesize(Blob::class);
                $getBlobResult4->getName()->will(function () {
                    return 'bar/foo/';
                });
                return [
                    $getBlobResult->reveal(),
                    $getBlobResult2->reveal(),
                    $getBlobResult3->reveal(),
                    $getBlobResult4->reveal()
                ];
            });
            return $listBlobsResult->reveal();
        });
    }

    /**
     * @return void
     */
    public function testIsFolderEmptyReturnsTrueIfNoFilesInFolder()
    {
 

        $this->isFolderEmptyMethodStubs();
        $result = $this->storageDriver->isFolderEmpty('bar');
        $options = new ListBlobsOptions();
        $options->setPrefix('bar/');
        $this->blobRestProxy->listBlobs(Arg::any(), $options)->shouldHaveBeenCalled();
        static::assertTrue($result);
    }

    /**
     * @return void
     */
    public function testIsFolderEmptyReturnsFalseIfFolderNotEmpty()
    {
        $this->isFolderEmptyNotEmptyMethodStubs();
        $result = $this->storageDriver->isFolderEmpty('bar');
        static::assertFalse($result);
    }

    protected function isFolderEmptyMethodStubs()
    {
 

        $this->blobRestProxy->listBlobs(Arg::cetera())->will(function () {
            $prophet = new Prophet();
            $listBlobsResult = $prophet->prophesize(ListBlobsResult::class);
            $listBlobsResult->getBlobs()->will(function () {
                return [];
            });
            return $listBlobsResult->reveal();
        });
    }

    protected function isFolderEmptyNotEmptyMethodStubs()
    {
 

        $this->blobRestProxy->listBlobs(Arg::cetera())->will(function () {
            $prophet = new Prophet();
            $listBlobsResult = $prophet->prophesize(ListBlobsResult::class);
            $listBlobsResult->getBlobs()->will(function () {
                return [123];
            });
            return $listBlobsResult->reveal();
        });
    }

    /**
     * @return void
     */
    public function testCreateFileCreatesEmptyFile()
    {
 

        $this->storageDriver->createFile('test.txt', 'foo');
        $this->blobRestProxy->createBlockBlob(Arg::any(), 'foo/test.txt', chr(26),
            Arg::cetera())->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testCreateFileReturnsNewFileIdentifier()
    {
        $result = $this->storageDriver->createFile('test.txt', 'foo');
        static::assertSame('foo/test.txt', $result);
    }

    /**
     * @return void
     */
    public function testCopyFileWithinStorageCallsCopyOnGivenFile()
    {
 

        $this->storageDriver->copyFileWithinStorage('foo/bar/baz.txt', 'baz/boo', 'bam.txt');
        $this->blobRestProxy->copyBlob(Arg::any(), 'baz/boo/bam.txt', Arg::any(),
            'foo/bar/baz.txt')->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testCopyFileWithinStorageReturnsNewFilename()
    {
        $result = $this->storageDriver->copyFileWithinStorage('foo/bar/baz.txt', 'baz/boo', 'bam.txt');
        static::assertSame('baz/boo/bam.txt', $result);
    }

    /**
     * @return void
     */
    public function testRenameFileCallsCopyAndDelete()
    {
 

        $this->storageDriver->renameFile('foo/bar/baz.txt', 'bum.txt');
        $this->blobRestProxy->copyBlob(Arg::any(), 'foo/bar/bum.txt', Arg::any(),
            'foo/bar/baz.txt')->shouldHaveBeenCalled();
        $this->blobRestProxy->deleteBlob(Arg::any(), 'foo/bar/baz.txt')->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testRenameFileReturnsNewFileName()
    {
        $result = $this->storageDriver->renameFile('foo/bar/baz.txt', 'bum.txt');
        static::assertSame('foo/bar/bum.txt', $result);
    }

    /**
     * @return void
     */
    public function testReplaceFileCallsCreate()
    {
 

        $this->storageDriver->replaceFile('foo/bar/bum.txt', $this->testFilePath);
        $this->blobRestProxy->createBlockBlob(Arg::any(), 'foo/bar/bum.txt', $this->testContent,
            Arg::any())->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testGetPermissionsReturnsRW()
    {
        $result = $this->storageDriver->getPermissions('');
        static::assertSame(
            ['r' => true, 'w' => true],
            $result
        );
    }

    /**
     * @return void
     */
    public function testGetFolderInFoldersSetsFolderPrefixToGivenFolder()
    {
 

        $this->folderInFolderMethodStubs();
        $this->storageDriver->getFoldersInFolder('foo');
        $options = new ListBlobsOptions();
        $options->setPrefix('foo/');
        $this->blobRestProxy->listBlobs(Arg::any(), $options)->shouldHaveBeenCalled();
    }


    /**
     * @return void
     */
    public function testGetFolderInFolderReturnsMatchingFolders()
    {
        $this->folderInFolderMethodStubs();
        $result = $this->storageDriver->getFoldersInFolder('bar');
        $expectedFolders = [
            'bar/test2/' => 'bar/test2/',
            'bar/foo/' => 'bar/foo/',
        ];
        static::assertSame($expectedFolders, $result);
    }

    /**
     * @return void
     */
    public function testGetFoldersInFolderReturnsAllSubFoldersIfRecursiveSetToTrue()
    {
        $this->folderInFolderMethodStubs();
        $result = $this->storageDriver->getFoldersInFolder('bar', 0, 0, true);
        $expectedFolders = [
            'bar/test2/' => 'bar/test2/',
            'bar/foo/baz/' => 'bar/foo/baz/',
            'bar/foo/' => 'bar/foo/',
        ];
        static::assertSame($expectedFolders, $result);
    }


    protected function folderInFolderMethodStubs()
    {
 

        $this->blobRestProxy->listBlobs(Arg::cetera())->will(function () {
            $prophet = new Prophet();
            $listBlobsResult = $prophet->prophesize(ListBlobsResult::class);
            $listBlobsResult->getBlobs()->will(function () {
                $prophet = new Prophet();
                $getBlobResult = $prophet->prophesize(Blob::class);
                $getBlobResult->getName()->will(function () {
                    return 'bar/test.txt';
                });
                $getBlobResult2 = $prophet->prophesize(Blob::class);
                $getBlobResult2->getName()->will(function () {
                    return 'bar/test2/';
                });
                $getBlobResult3 = $prophet->prophesize(Blob::class);
                $getBlobResult3->getName()->will(function () {
                    return 'bar/foo/baz/';
                });
                $getBlobResult4 = $prophet->prophesize(Blob::class);
                $getBlobResult4->getName()->will(function () {
                    return 'bar/foo/';
                });
                $getBlobResult5 = $prophet->prophesize(Blob::class);
                $getBlobResult5->getName()->will(function () {
                    return 'bar/';
                });
                return [
                    $getBlobResult->reveal(),
                    $getBlobResult2->reveal(),
                    $getBlobResult3->reveal(),
                    $getBlobResult4->reveal(),
                    $getBlobResult5->reveal()
                ];
            });
            return $listBlobsResult->reveal();
        });
    }

    /**
     * @return void
     */
    public function testHashReturnsSha1OfIdentifier()
    {
        $result = $this->storageDriver->hash('test/bar.txt', '');
        static::assertSame(sha1('test/bar.txt'), $result);
    }

    /**
     * @return void
     */
    public function testHashIdentifierReturnsSha1OfIdentifier()
    {
        $result = $this->storageDriver->hashIdentifier('test/bar.txt');
        static::assertSame(sha1('test/bar.txt'), $result);
    }

    /**
     * @return void
     */
    public function testGetPublicUrlReturnsUrl()
    {
        $this->storageDriver->setContainer('foocontainer');
        $this->storageDriver->setStorageAccountName('teststorageaccount');
        $this->storageDriver->setProtocol('https');
        $result = $this->storageDriver->getPublicUrl('foo/bar.txt');
        static::assertSame('https://teststorageaccount.blob.core.windows.net/foocontainer/foo/bar.txt', $result);
    }

    /**
     * @return void
     */
    public function testGetRootFolderReturnsEmptyString()
    {
        $result = $this->storageDriver->getRootLevelFolder();
        static::assertSame('', $result);
    }

    /**
     * @return void
     */
    public function testGetDefaultFolderReturnsRootLevelFolder()
    {
        $result = $this->storageDriver->getDefaultFolder();
        static::assertSame($this->storageDriver->getRootLevelFolder(), $result);
    }

    /**
     * @return void
     */
    public function testMergeConfigurationCapabilitiesReturnsCapabilities()
    {
        $result = $this->storageDriver->mergeConfigurationCapabilities(3);
        static::assertSame(3, $result);
    }

    /**
     * @return void
     */
    public function testMergeConfigurationCapabilitiesReturnsZeroForNonMatchingBitMask()
    {
        $result = $this->storageDriver->mergeConfigurationCapabilities(8);
        static::assertSame(0, $result);
    }

    /**
     * @return void
     */
    public function testProcessConfigurationSetsConfigOptions()
    {
        $storageDriver = new BlobStorageDriver([
            'containerName' => 'mycontainer',
            'accountKey' => '123',
            'accountName' => 'foo',
            'defaultEndpointsProtocol' => 'http'
        ]);
        $storageDriver->initialize($this->blobRestProxy->reveal());
        $storageDriver->processConfiguration();
        static::assertSame('mycontainer', $storageDriver->getContainer());
        static::assertSame('123', $storageDriver->getStorageAccountKey());
        static::assertSame('foo', $storageDriver->getStorageAccountName());
        static::assertSame('http', $storageDriver->getProtocol());
    }

    /**
     * @return void
     */
    public function testGetFolderInfoByIdentifierGetsContainerPropertiesIfRootFolderGiven()
    {
        $containerPropertiesProphecy = $this->prophesize(ContainerProperties::class);
        $containerPropertiesProphecy->getLastModified()->willReturn(new \DateTime());
        $containerProperties = $containerPropertiesProphecy->reveal();

        $this->blobRestProxy->getContainerProperties(Arg::any())->willReturn($containerProperties);
        $this->storageDriver->getFolderInfoByIdentifier('/');
        $this->blobRestProxy->getContainerProperties(Arg::any())->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testContainerGetsCreatedIfItDoesNotAlreadyExist()
    {
        $storageDriver = new BlobStorageDriver([
            'containerName' => 'foobarbaz',
            'accountKey' => '123',
            'accountName' => 'foo',
            'defaultEndpointsProtocol' => 'http'
        ]);
        $storageDriver->initialize($this->blobRestProxy->reveal());
        $this->blobRestProxy->createContainer('foobarbaz', Arg::any())->shouldHaveBeenCalled();
    }

    /**
     * @return void
     */
    public function testContainerSetsFlashMessageToErrorIfCodeNot409()
    {
        $this->blobRestProxy->createContainer(Arg::cetera())->will(function () {
            throw new ServiceException(410, 'test 123', 'test 234');
        });
        $this->storageDriver->initialize($this->blobRestProxy->reveal());
        $this->flashMessageService->getFlashMessageInstance(Arg::any(), Arg::any(), Arg::cetera())->shouldHaveBeenCalled();
    }
}
