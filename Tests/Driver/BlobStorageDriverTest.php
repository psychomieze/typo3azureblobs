<?php
namespace Neusta\AzureBlobs\Tests\Driver;

use Neusta\AzureBlobs\Driver\BlobStorageDriver;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamWrapper;
use Prophecy\Argument as Arg;
use Prophecy\Prophet;
use WindowsAzure\Blob\Models\CreateBlobOptions;
use WindowsAzure\Blob\Models\GetBlobResult;
use WindowsAzure\Blob\Models\ListBlobsOptions;
use WindowsAzure\Common\ServiceException;

require_once(__DIR__ . '/../../Classes/Driver/BlobStorageDriver.php');
require_once(__DIR__ . '/../../Classes/Service/FlashMessageService.php');

class BlobStorageDriverTest extends \PHPUnit_Framework_TestCase {
	protected $flashMessageQueue;
	protected $flashMessageService;

	/**
	 * @var \Prophecy\Prophet
	 */
	protected $prophet;

	/**
	 * @var \WindowsAzure\Blob\Internal\IBlob
	 */
	private $blobRestProxy = NULL;

	/**
	 * @var BlobStorageDriver
	 */
	private $storageDriver = NULL;

	private $testFile;
	private $emptyTestFile;
	private $emptyTestFilePath;
	private $testFilePath;
	private $testContent = 'this is test content.';

	public function setUp() {
		$this->prophet = new Prophet();
		$this->blobRestProxy = $this->prophet->prophesize('\WindowsAzure\Blob\Internal\IBlob');
		$this->flashMessageQueue = $this->prophet->prophesize('\TYPO3\CMS\Core\Messaging\FlashMessageQueue');
		$this->flashMessageService = $this->prophet->prophesize('\Neusta\AzureBlobs\Service\FlashMessageService');
		$this->storageDriver = new BlobStorageDriver(['containerName' => 'mycontainer', 'accountKey' => '123', 'accountName' => 'foo', 'defaultEndpointsProtocol' => 'http']);
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
	 * @test
	 * @return void
	 */
	public function testCreateObjectCallsCreateBlockBlob() {
		$this->storageDriver->createObject('foo/bar');
		$this->blobRestProxy->createBlockBlob('mycontainer', 'foo/bar', ' ', Arg::any())->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testCreateObjectUsesConfiguredContainer() {
		$this->storageDriver->setContainer('foo');
		$this->storageDriver->createObject('bar/baz.txt');
		$this->blobRestProxy->createBlockBlob('foo', 'bar/baz.txt', ' ', Arg::any())->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testCreateObjectAddsContentToBlob() {
		$this->storageDriver->createObject('foo/bar.txt', 'test content');
		$this->blobRestProxy->createBlockBlob('mycontainer', 'foo/bar.txt', 'test content', Arg::any())->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testCreateObjectThrowsExceptionIfContentIsNoString() {
		$this->setExpectedException('InvalidArgumentException');
		$this->storageDriver->createObject('foo/bar.txt', ['foo' => 'bar'])->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testAddFileSetsFilenameToBasenameIfNotGiven() {
		$this->storageDriver->addFile($this->testFilePath, 'fileadmin/test/');
		$this->blobRestProxy->createBlockBlob('mycontainer', 'fileadmin/test/test.txt', Arg::any(), Arg::any())->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testAddFileSetsFilenameToGivenFilename() {
		$this->storageDriver->addFile($this->testFilePath, 'fileadmin/test', 'foo.txt');
		$this->blobRestProxy->createBlockBlob('mycontainer', 'fileadmin/test/foo.txt', Arg::any(), Arg::any())->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testAddFileSetsMimeTypeInfo() {
		$this->storageDriver->addFile($this->testFilePath, 'fileadmin/test');
		$options = new CreateBlobOptions;
		$options->setContentType('text/plain');
		$this->blobRestProxy->createBlockBlob('mycontainer', 'fileadmin/test/test.txt', Arg::any(), Arg::exact($options))->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testAddFileSetsContent() {
		$this->storageDriver->addFile($this->testFilePath, 'fileadmin/test');
		$this->blobRestProxy->createBlockBlob('mycontainer', 'fileadmin/test/test.txt', $this->testContent, Arg::any())->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testAddFileReturnsTargetIdentifier() {
		$targetIdentifier = $this->storageDriver->addFile($this->testFilePath, 'fileadmin/test');
		$this->assertSame('fileadmin/test/test.txt', $targetIdentifier);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testAddFolderCreatesRootLevelFolderIfNoParentFolderGiven() {
		$this->storageDriver->createFolder('foo');
		$this->blobRestProxy->createBlockBlob(Arg::any(), 'foo/', Arg::cetera())->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testAddFolderCreatesFolderWithParentPrefixed() {
		$this->storageDriver->createFolder('foo', 'bar');
		$this->blobRestProxy->createBlockBlob(Arg::any(), 'bar/foo/', Arg::cetera())->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testFileExistsReturnsFalseIfFolderNameGiven() {
		$return = $this->storageDriver->fileExists('foo/');
		$this->assertFalse($return);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testFileExistsReturnsFalseIfWebserviceThrowsException() {
		$this->blobRestProxy->getBlob(Arg::cetera())->will(function () {
			throw new ServiceException(123);
		});
		$result = $this->storageDriver->fileExists('bar.txt');
		$this->assertFalse($result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testFileExistsReturnsTrueIfWebserviceDoesNotThrowException() {
		$this->blobRestProxy->getBlob(Arg::cetera())->willReturn(TRUE);
		$result = $this->storageDriver->fileExists('bar.txt');
		$this->assertTrue($result);
	}


	/**
	 * @test
	 * @return void
	 */
	public function testDeleteFileCallsDeleteBlob() {
		$this->storageDriver->deleteFile('test.txt');
		$this->blobRestProxy->deleteBlob(Arg::any(), 'test.txt')->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testDeleteFileReturnsFalseIfExceptionIsThrown() {
		$this->blobRestProxy->deleteBlob(Arg::cetera())->will(function () {
			throw new ServiceException(123);
		});
		$result = $this->storageDriver->deleteFile('test.txt');
		$this->assertFalse($result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testDeleteFileReturnsTrueIfNoExceptionIsThrown() {
		$result = $this->storageDriver->deleteFile('test.txt');
		$this->assertTrue($result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testMoveFileWithinStorageCopyAndDeletesFile() {
		$this->storageDriver->moveFileWithinStorage('foo.txt', 'bar', 'baz.txt');
		$this->blobRestProxy->copyBlob(Arg::any(), 'bar/baz.txt', Arg::any(), 'foo.txt')->shouldHaveBeenCalled();
		$this->blobRestProxy->deleteBlob(Arg::any(), 'foo.txt')->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testMoveFileWithinStorageUsesConfiguredContainer() {
		$this->storageDriver->setContainer('foo');
		$this->storageDriver->moveFileWithinStorage('test.txt', 'test', 'bar.txt');
		$this->blobRestProxy->copyBlob('foo', Arg::any(), 'foo', Arg::any())->shouldHaveBeenCalled();
		$this->blobRestProxy->deleteBlob('foo', Arg::cetera())->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testMoveFileWithinStorageReturnsNewFilename() {
		$result = $this->storageDriver->moveFileWithinStorage('test.txt', 'bar', 'baz.txt');
		$this->assertSame('bar/baz.txt', $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testMoveFolderWithinStorageFetchesAllElementsInFolder() {
		$this->storageDriver->setContainer('foo');
		$this->storageDriver->moveFolderWithinStorage('bar', 'foo', 'hans');
		$options = new ListBlobsOptions();
		$options->setPrefix('bar/');
		$this->blobRestProxy->listBlobs('foo', $options)->shouldHaveBeenCalled();
	}

	public function testMoveFolderWithinStorageCallsDeleteAndCopyForEachFileWithin() {
		$this->moveFolderMethodStubs();

		$this->storageDriver->moveFolderWithinStorage('bar', 'foo', 'hans');
		$this->blobRestProxy->copyBlob(Arg::any(), 'foo/hans/test.txt', Arg::any(), 'bar/test.txt')->shouldHaveBeenCalled();
		$this->blobRestProxy->deleteBlob(Arg::any(), 'bar/test.txt')->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testMoveFolderWithinStorageReturnsAffectedFilesArray() {
		$this->moveFolderMethodStubs();

		$result = $this->storageDriver->moveFolderWithinStorage('bar', 'foo', 'hans');
		$this->assertSame([
			'bar/test.txt' => 'foo/hans/test.txt'
		], $result);
	}

	protected function moveFolderMethodStubs() {
		$this->blobRestProxy->listBlobs(Arg::cetera())->will(function () {
			$prophet = new Prophet();
			$listBlobsResult = $prophet->prophesize('WindowsAzure\Blob\Models\ListBlobsResult');
			$listBlobsResult->getBlobs()->will(function () {
				$prophet = new Prophet();
				$getBlobResult = $prophet->prophesize('WindowsAzure\Blob\Models\Blob');
				$getBlobResult->getName()->will(function () {
					return 'bar/test.txt';
				});
				return [$getBlobResult->reveal()];
			});
			return $listBlobsResult->reveal();
		});
		$this->blobRestProxy->copyBlob(Arg::cetera())->willReturn(true);
		$this->blobRestProxy->deleteBlob(Arg::cetera())->willReturn(true);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testCopyFolderWithinStorage() {
		$this->moveFolderMethodStubs();

		$this->storageDriver->copyFolderWithinStorage('bar', 'foo', 'karla');
		$this->blobRestProxy->copyBlob(Arg::any(), 'foo/karla/test.txt', Arg::any(), 'bar/test.txt')->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testRenameFolder() {
		$this->moveFolderMethodStubs();
		$this->storageDriver->renameFolder('bar/', 'bom/');
		$this->blobRestProxy->copyBlob(Arg::any(), 'bom/test.txt', Arg::any(), 'bar/test.txt')->shouldHaveBeenCalled();
		$this->blobRestProxy->deleteBlob(Arg::any(), 'bar/test.txt')->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetFileContentsReturnsContent() {
		$blobProphecy = $this->prophet->prophesize('WindowsAzure\Blob\Models\GetBlobResult');
		$blobProphecy->getContentStream()->willReturn(fopen($this->testFilePath, 'r'));
		$this->blobRestProxy->getBlob(Arg::cetera())->willReturn($blobProphecy->reveal());
		$result = $this->storageDriver->getFileContents('test.txt');
		$this->assertSame($this->testContent, $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testDeleteFolderCallsDeleteForFilesInFolder() {
		$this->moveFolderMethodStubs();
		$this->storageDriver->deleteFolder('bar/');
		$this->blobRestProxy->deleteBlob(Arg::any(), 'bar/test.txt')->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testDeleteFolderFetchesAllFilesInFolderToDelete() {
		$this->moveFolderMethodStubs();
		$options = new ListBlobsOptions();
		$options->setPrefix('bar/');
		$this->storageDriver->deleteFolder('bar/');
		$this->blobRestProxy->listBlobs(Arg::any(), $options)->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testSetFileContents() {
		$this->storageDriver->setFileContents('foo/bar/baz.txt', 'hallo welt.');
		$this->blobRestProxy->createBlockBlob(Arg::any(), 'foo/bar/baz.txt', 'hallo welt.');
	}

	/**
	 * @test
	 * @return void
	 */
	public function testFileExistsInFolderReturnsFalseIfNonExistingFileGiven() {
		$return = $this->storageDriver->fileExistsInFolder('test.txt', 'bar');
		$this->assertFalse($return);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testFileExistsInFolderReturnsTrueIfFileExists() {
		$this->blobRestProxy->getBlob(Arg::any(), 'bar/test.txt')->willReturn(TRUE);
		$return = $this->storageDriver->fileExistsInFolder('test.txt', 'bar');
		$this->blobRestProxy->getBlob(Arg::any(), 'bar/test.txt')->shouldHaveBeenCalled();
		$this->assertTrue($return);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testFolderExistsInFolderReturnsFalseIfFolderDoesNotExist() {
		$result = $this->storageDriver->folderExistsInFolder('foo', 'bar');
		$this->assertFalse($result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testFolderExistsInFolderReturnsTrueIfFolderExists() {
		$this->blobRestProxy->getBlob(Arg::any(), 'foo/bar/')->willReturn(TRUE);
		$result = $this->storageDriver->folderExistsInFolder('bar', 'foo');
		$this->blobRestProxy->getBlob(Arg::any(), 'foo/bar/')->shouldHaveBeenCalled();
		$this->assertTrue($result);
	}
	/**
	 * @test
	 * @return void
	 */
	public function testGetFileForLocalProcessingWritesToFile() {
		$blobProphecy = $this->prophet->prophesize('WindowsAzure\Blob\Models\GetBlobResult');
		$blobProphecy->getContentStream()->willReturn(fopen($this->testFilePath, 'r'));
		$messageQueue = $this->prophet->prophesize('\TYPO3\CMS\Core\Messaging\FlashMessageQueue');
		$this->blobRestProxy->getBlob(Arg::cetera())->willReturn($blobProphecy->reveal());
		$storageMock = $this
			->getMockBuilder('Neusta\AzureBlobs\Driver\BlobStorageDriver')
			->setMethods(['getTemporaryPathForFile', 'createContainer'])
			->setConstructorArgs([['containerName' => 'mycontainer', 'accountKey' => '123', 'accountName' => 'foo', 'defaultEndpointsProtocol' => 'http']])
			->getMock();
		$storageMock->setMessageQueueByIdentifier($messageQueue);
		$storageMock->initialize($this->blobRestProxy->reveal());
		$storageMock->expects($this->any())->method('getTemporaryPathForFile')->will($this->returnValue(vfsStream::url('exampleDir/test2.txt')));
		$return = $storageMock->getFileForLocalProcessing('bar/test.txt');
		$this->assertSame($this->testContent, stream_get_contents(fopen($return, 'r')));
	}

	/**
	 * @test
	 * @return void
	 */
	public function testIsWithinReturnsTrueIfSubfolderPathIsTheSame() {
		$result = $this->storageDriver->isWithin('foo/bar/', 'foo/bar/baz.txt');
		$this->assertTrue($result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testIsWithinReturnsTrueIfFileOnRootIsGiven() {
		$result = $this->storageDriver->isWithin('', 'baz.txt');
		$this->assertTrue($result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testIsWithinReturnsTrueIfSameIdentifierIsGiven() {
		$result = $this->storageDriver->isWithin('foo/', 'foo/');
		$this->assertTrue($result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testIsWithinReturnsFalseIfFileIsNotInFolder() {
		$result = $this->storageDriver->isWithin('foo/', 'baz.txt');
		$this->assertFalse($result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testIsWithinReturnsFalseIfFileIsInSameSubfolderStructureButAsSisterElement() {
		$result = $this->storageDriver->isWithin('foo/bar', 'foo/baz/test.txt');
		$this->assertFalse($result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testFolderExistsReturnsTrueIfBlobWithFolderNameExists(){
		$this->blobRestProxy->getBlob(Arg::any(), 'foo/')->willReturn(TRUE);
		$result = $this->storageDriver->folderExists('foo');
		$this->blobRestProxy->getBlob(Arg::any(), 'foo/')->shouldHaveBeenCalled();
		$this->assertTrue($result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testFolderExistsReturnsTrueIfRootFolder() {
		$result = $this->storageDriver->folderExists('/');
		$this->assertTrue($result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testFolderExistsReturnsFalseIfBlobWithFolderNameDoesNotExist() {
		$this->blobRestProxy->getBlob(Arg::any(), 'bar/')->willReturn(FALSE);
		$result = $this->storageDriver->folderExists('bar');
		$this->blobRestProxy->getBlob(Arg::any(), 'bar/')->shouldHaveBeenCalled();
		$this->assertFalse($result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetFileInfoByIdentifierReturnsArrayWithBasicInformation() {
		$this->getFileOrFolderInfoMethodMocks('foo/test.txt');
		$this->storageDriver->setStorageUid(1);
		$result = $this->storageDriver->getFileInfoByIdentifier('foo/test.txt');
		$this->assertSame('foo/test.txt', $result['identifier']);
		$this->assertSame('test.txt', $result['name']);
		$this->assertSame(1, $result['storage']);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetFolderInfoByIdentifierReturnsArrayWithBasicInformation(){
		$this->getFileOrFolderInfoMethodMocks('foo/');
		$this->storageDriver->setStorageUid(1);
		$result = $this->storageDriver->getFolderInfoByIdentifier('foo/');
		$this->assertSame('foo/', $result['identifier']);
		$this->assertSame('foo', $result['name']);
		$this->assertSame(1, $result['storage']);
	}

	protected function getFileOrFolderInfoMethodMocks($fileOrFolder){
		$blobPropertiesProphecy = $this->prophet->prophesize('\WindowsAzure\Blob\Models\BlobProperties');
		$blobPropertiesProphecy->getLastModified()->willReturn(new \DateTime());
		$blobPropertiesProphecy->getContentLength()->willReturn(new \DateTime());
		$blobProperties = $blobPropertiesProphecy->reveal();

		$blobProphecy = $this->prophet->prophesize('WindowsAzure\Blob\Models\GetBlobResult');
		$blobProphecy->getProperties()->willReturn($blobProperties);
		$this->blobRestProxy->getBlob(Arg::any(), $fileOrFolder)->willReturn($blobProphecy->reveal());
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetFilesInFolderSetsPrefix() {
		$this->filesInFolderMethodStubs();
		$options = new ListBlobsOptions();
		$options->setPrefix('foo/');
		$this->storageDriver->getFilesInFolder('foo');
		$this->blobRestProxy->listBlobs(Arg::any(), $options)->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetFilesInFolderReturnsFilesInFolder() {
		$this->filesInFolderMethodStubs();
		$expectedFiles = [
			'bar/test.txt' => 'bar/test.txt',
			'bar/test2.txt' => 'bar/test2.txt'
		];
		$returnedFiles = $this->storageDriver->getFilesInFolder('bar');
		$this->assertSame($expectedFiles, $returnedFiles);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetFilesInFolderReturnsEmptyArrayIfNoFilesFound() {
		$result = $this->storageDriver->getFilesInFolder('aoeuhsn');
		$this->assertSame([], $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetFilesInFolderReturnsFilesInSubfoldersIfRecursiveSetToTrue() {
		$this->filesInFolderMethodStubs();
		$expectedFiles = [
			'bar/test.txt' => 'bar/test.txt',
			'bar/test2.txt' => 'bar/test2.txt',
			'bar/foo/test2.txt' => 'bar/foo/test2.txt'
		];
		$returnedFiles = $this->storageDriver->getFilesInFolder('bar', 0, 0, TRUE);
		$this->assertSame($expectedFiles, $returnedFiles);
	}

	protected function filesInFolderMethodStubs() {
		$this->blobRestProxy->listBlobs(Arg::cetera())->will(function () {
			$prophet = new Prophet();
			$listBlobsResult = $prophet->prophesize('WindowsAzure\Blob\Models\ListBlobsResult');
			$listBlobsResult->getBlobs()->will(function () {
				$prophet = new Prophet();
				$getBlobResult = $prophet->prophesize('WindowsAzure\Blob\Models\Blob');
				$getBlobResult->getName()->will(function () {
					return 'bar/test.txt';
				});
				$getBlobResult2 = $prophet->prophesize('WindowsAzure\Blob\Models\Blob');
				$getBlobResult2->getName()->will(function () {
					return 'bar/test2.txt';
				});
				$getBlobResult3 = $prophet->prophesize('WindowsAzure\Blob\Models\Blob');
				$getBlobResult3->getName()->will(function () {
					return 'bar/foo/test2.txt';
				});
				$getBlobResult4 = $prophet->prophesize('WindowsAzure\Blob\Models\Blob');
				$getBlobResult4->getName()->will(function () {
					return 'bar/foo/';
				});
				return [$getBlobResult->reveal(), $getBlobResult2->reveal(), $getBlobResult3->reveal(), $getBlobResult4->reveal()];
			});
			return $listBlobsResult->reveal();
		});
	}

	/**
	 * @test
	 * @return void
	 */
	public function testIsFolderEmptyReturnsTrueIfNoFilesInFolder(){
		$this->isFolderEmptyMethodStubs();
		$result = $this->storageDriver->isFolderEmpty('bar');
		$options = new ListBlobsOptions();
		$options->setPrefix('bar/');
		$this->blobRestProxy->listBlobs(Arg::any(), $options)->shouldHaveBeenCalled();
		$this->assertTrue($result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testIsFolderEmptyReturnsFalseIfFolderNotEmpty() {
		$this->isFolderEmptyNotEmptyMethodStubs();
		$result = $this->storageDriver->isFolderEmpty('bar');
		$this->assertFalse($result);
	}

	protected function isFolderEmptyMethodStubs() {
		$this->blobRestProxy->listBlobs(Arg::cetera())->will(function () {
			$prophet = new Prophet();
			$listBlobsResult = $prophet->prophesize('WindowsAzure\Blob\Models\ListBlobsResult');
			$listBlobsResult->getBlobs()->will(function () {
				return [];
			});
			return $listBlobsResult->reveal();
		});
	}

	protected function isFolderEmptyNotEmptyMethodStubs() {
		$this->blobRestProxy->listBlobs(Arg::cetera())->will(function () {
			$prophet = new Prophet();
			$listBlobsResult = $prophet->prophesize('WindowsAzure\Blob\Models\ListBlobsResult');
			$listBlobsResult->getBlobs()->will(function () {
				return [123];
			});
			return $listBlobsResult->reveal();
		});
	}

	/**
	 * @test
	 * @return void
	 */
	public function testCreateFileCreatesEmptyFile() {
		$this->storageDriver->createFile('test.txt', 'foo');
		$this->blobRestProxy->createBlockBlob(Arg::any(), 'foo/test.txt', chr(26), Arg::cetera())->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testCreateFileReturnsNewFileIdentifier() {
		$result = $this->storageDriver->createFile('test.txt', 'foo');
		$this->assertSame('foo/test.txt', $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testCopyFileWithinStorageCallsCopyOnGivenFile() {
		$this->storageDriver->copyFileWithinStorage('foo/bar/baz.txt', 'baz/boo', 'bam.txt');
		$this->blobRestProxy->copyBlob(Arg::any(), 'baz/boo/bam.txt', Arg::any(), 'foo/bar/baz.txt')->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testCopyFileWithinStorageReturnsNewFilename() {
		$result = $this->storageDriver->copyFileWithinStorage('foo/bar/baz.txt', 'baz/boo', 'bam.txt');
		$this->assertSame('baz/boo/bam.txt', $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testRenameFileCallsCopyAndDelete() {
		$this->storageDriver->renameFile('foo/bar/baz.txt', 'bum.txt');
		$this->blobRestProxy->copyBlob(Arg::any(), 'foo/bar/bum.txt', Arg::any(), 'foo/bar/baz.txt')->shouldHaveBeenCalled();
		$this->blobRestProxy->deleteBlob(Arg::any(), 'foo/bar/baz.txt')->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testRenameFileReturnsNewFileName() {
		$result = $this->storageDriver->renameFile('foo/bar/baz.txt', 'bum.txt');
		$this->assertSame('foo/bar/bum.txt', $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testReplaceFileCallsCreate() {
		$this->storageDriver->replaceFile('foo/bar/bum.txt', $this->testFilePath);
		$this->blobRestProxy->createBlockBlob(Arg::any(), 'foo/bar/bum.txt', $this->testContent, Arg::any())->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetPermissionsReturnsRW() {
		$result = $this->storageDriver->getPermissions('');
		$this->assertSame(
			['r' => TRUE, 'w' => TRUE],
			$result
		);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetFolderInFoldersSetsFolderPrefixToGivenFolder() {
		$this->folderInFolderMethodStubs();
		$this->storageDriver->getFoldersInFolder('foo');
		$options = new ListBlobsOptions();
		$options->setPrefix('foo/');
		$this->blobRestProxy->listBlobs(Arg::any(), $options)->shouldHaveBeenCalled();
	}


	/**
	 * @test
	 * @return void
	 */
	public function testGetFolderInFolderReturnsMatchingFolders() {
		$this->folderInFolderMethodStubs();
		$result = $this->storageDriver->getFoldersInFolder('bar');
		$expectedFolders = [
			'bar/test2/' => 'bar/test2/',
			'bar/foo/' => 'bar/foo/',
		];
		$this->assertSame($expectedFolders, $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetFoldersInFolderReturnsAllSubFoldersIfRecursiveSetToTrue() {
		$this->folderInFolderMethodStubs();
		$result = $this->storageDriver->getFoldersInFolder('bar', 0, 0, TRUE);
		$expectedFolders = [
			'bar/test2/' => 'bar/test2/',
			'bar/foo/baz/' => 'bar/foo/baz/',
			'bar/foo/' => 'bar/foo/',
		];
		$this->assertSame($expectedFolders, $result);
	}


	protected function folderInFolderMethodStubs() {
		$this->blobRestProxy->listBlobs(Arg::cetera())->will(function () {
			$prophet = new Prophet();
			$listBlobsResult = $prophet->prophesize('WindowsAzure\Blob\Models\ListBlobsResult');
			$listBlobsResult->getBlobs()->will(function () {
				$prophet = new Prophet();
				$getBlobResult = $prophet->prophesize('WindowsAzure\Blob\Models\Blob');
				$getBlobResult->getName()->will(function () {
					return 'bar/test.txt';
				});
				$getBlobResult2 = $prophet->prophesize('WindowsAzure\Blob\Models\Blob');
				$getBlobResult2->getName()->will(function () {
					return 'bar/test2/';
				});
				$getBlobResult3 = $prophet->prophesize('WindowsAzure\Blob\Models\Blob');
				$getBlobResult3->getName()->will(function () {
					return 'bar/foo/baz/';
				});
				$getBlobResult4 = $prophet->prophesize('WindowsAzure\Blob\Models\Blob');
				$getBlobResult4->getName()->will(function () {
					return 'bar/foo/';
				});
				$getBlobResult5 = $prophet->prophesize('WindowsAzure\Blob\Models\Blob');
				$getBlobResult5->getName()->will(function () {
					return 'bar/';
				});
				return [$getBlobResult->reveal(), $getBlobResult2->reveal(), $getBlobResult3->reveal(), $getBlobResult4->reveal(), $getBlobResult5->reveal()];
			});
			return $listBlobsResult->reveal();
		});
	}

	/**
	 * @test
	 * @return void
	 */
	public function testHashReturnsSha1OfIdentifier() {
		$result = $this->storageDriver->hash('test/bar.txt', '');
		$this->assertSame(sha1('test/bar.txt'), $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testHashIdentifierReturnsSha1OfIdentifier(){
		$result = $this->storageDriver->hashIdentifier('test/bar.txt');
		$this->assertSame(sha1('test/bar.txt'), $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetPublicUrlReturnsUrl() {
		$this->storageDriver->setContainer('foocontainer');
		$this->storageDriver->setStorageAccountName('teststorageaccount');
		$this->storageDriver->setProtocol('https');
		$result = $this->storageDriver->getPublicUrl('foo/bar.txt');
		$this->assertSame('https://teststorageaccount.blob.core.windows.net/foocontainer/foo/bar.txt', $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetRootFolderReturnsEmptyString() {
		$result = $this->storageDriver->getRootLevelFolder();
		$this->assertSame('/', $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetDefaultFolderReturnsRootLevelFolder() {
		$result = $this->storageDriver->getDefaultFolder();
		$this->assertSame($this->storageDriver->getRootLevelFolder(), $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testMergeConfigurationCapabilitiesReturnsCapabilities() {
		$result = $this->storageDriver->mergeConfigurationCapabilities(3);
		$this->assertSame(3, $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testMergeConfigurationCapabilitiesReturnsZeroForNonMatchingBitMask() {
		$result = $this->storageDriver->mergeConfigurationCapabilities(8);
		$this->assertSame(0, $result);
	}

	/**
	 * @test
	 * @return void
	 */
	public function testProcessConfigurationThrowsExceptionIfKeyIsNotSet() {
		$storageDriver = new BlobStorageDriver([]);
		$this->setExpectedException('\InvalidArgumentException', 'The required configuration setting "containerName" was not set!');
		$storageDriver->processConfiguration();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testProcessConfigurationSetsConfigOptions() {
		$storageDriver = new BlobStorageDriver(['containerName' => 'mycontainer', 'accountKey' => '123', 'accountName' => 'foo', 'defaultEndpointsProtocol' => 'http']);
		$storageDriver->initialize($this->blobRestProxy->reveal());
		$storageDriver->processConfiguration();
		$this->assertSame('mycontainer', $storageDriver->getContainer());
		$this->assertSame('123', $storageDriver->getStorageAccountKey());
		$this->assertSame('foo', $storageDriver->getStorageAccountName());
		$this->assertSame('http', $storageDriver->getProtocol());
	}

	/**
	 * @test
	 * @return void
	 */
	public function testGetFolderInfoByIdentifierGetsContainerPropertiesIfRootFolderGiven() {
		$containerPropertiesProphecy = $this->prophet->prophesize('\WindowsAzure\Blob\Models\ContainerProperties');
		$containerPropertiesProphecy->getLastModified()->willReturn(new \DateTime());
		$containerProperties = $containerPropertiesProphecy->reveal();

		$this->blobRestProxy->getContainerProperties(Arg::any())->willReturn($containerProperties);
		$this->storageDriver->getFolderInfoByIdentifier('/');
		$this->blobRestProxy->getContainerProperties(Arg::any())->shouldHaveBeenCalled();
	}

	/**
	 * @test
	 * @return void
	 */
	public function testContainerGetsCreatedIfItDoesNotAlreadyExist() {
		$storageDriver = new BlobStorageDriver(['containerName' => 'foobarbaz', 'accountKey' => '123', 'accountName' => 'foo', 'defaultEndpointsProtocol' => 'http']);
		$storageDriver->initialize($this->blobRestProxy->reveal());
		$this->blobRestProxy->createContainer('foobarbaz', Arg::any())->shouldHaveBeenCalled();
	}

	public function testContainerSetsFlashMessageToErrorIfCodeNot409() {
		$this->blobRestProxy->createContainer(Arg::cetera())->will(function() {
			throw new ServiceException(410, 'test 123', 'test 234');
		});
		$this->storageDriver->initialize($this->blobRestProxy->reveal());
		$this->flashMessageService->getFlashMessageInstance(Arg::cetera())->shouldHaveBeenCalled();
	}

}
