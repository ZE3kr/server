<?php

declare(strict_types=1);

namespace OC\Core\Command\Debug;

use OC\Files\ObjectStore\ObjectStoreStorage;
use OCA\Circles\MountManager\CircleMount;
use OCA\Files_External\Config\ExternalMountPoint;
use OCA\Files_Sharing\SharedMount;
use OCA\GroupFolders\Mount\GroupMountPoint;
use OCP\Constants;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\FileInfo;
use OCP\Files\IHomeStorage;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Share\IShare;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class File extends Command {
	private IRootFolder $rootFolder;
	private IUserMountCache $userMountCache;
	private IL10N $l10n;

	public function __construct(IRootFolder $rootFolder, IUserMountCache $userMountCache, IFactory $l10nFactory) {
		$this->rootFolder = $rootFolder;
		$this->userMountCache = $userMountCache;
		$this->l10n = $l10nFactory->get("files");
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('debug:file')
			->setDescription('get information for a file')
			->addArgument('file', InputArgument::REQUIRED, "File id or path");
	}

	public function execute(InputInterface $input, OutputInterface $output): int {
		$fileInput = $input->getArgument('file');
		$file = $this->getFile($fileInput);
		if (!$file) {
			$output->writeln("<error>file $fileInput not found</error>");
			return 1;
		}

		$output->writeln($file->getName());
		$output->writeln("  fileid: " . $file->getId());
		$output->writeln("  mimetype: " . $file->getMimetype());
		$output->writeln("  modified: " . $this->l10n->l("datetime", $file->getMTime()));
		$output->writeln("  " . ($file->isEncrypted() ? "encrypted" : "not encrypted"));
		$this->storageDetails($file->getMountPoint(), $file, $output);

		$filesPerUser = $this->getFilesByUser($file);
		$output->writeln("");
		$output->writeln("The following users have access to the file");
		$output->writeln("");
		foreach ($filesPerUser as $user => $files) {
			$output->writeln("$user:");
			foreach ($files as $userFile) {
				$output->writeln("  " . $userFile->getPath() . ": " . $this->formatPermissions($userFile->getType(), $userFile->getPermissions()));
				$mount = $userFile->getMountPoint();
				$output->writeln("    " . $this->formatMountType($mount));
			}
		}

		return 0;
	}

	private function getFile(string $fileInput): ?Node {
		if (is_numeric($fileInput)) {
			$mounts = $this->userMountCache->getMountsForFileId((int)$fileInput);
			if (!$mounts) {
				return null;
			}
			$mount = $mounts[0];
			$userFolder = $this->rootFolder->getUserFolder($mount->getUser()->getUID());
			$nodes = $userFolder->getById($fileInput);
			if (!$nodes) {
				return null;
			}
			return $nodes[0];
		} else {
			try {
				return $this->rootFolder->get($fileInput);
			} catch (NotFoundException $e) {
				return null;
			}
		}
	}

	/**
	 * @param FileInfo $file
	 * @return array<string, Node[]>
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OC\User\NoUserException
	 */
	private function getFilesByUser(FileInfo $file): array {
		$mounts = $this->userMountCache->getMountsForFileId($file->getId());
		$result = [];
		foreach ($mounts as $mount) {
			if (isset($result[$mount->getUser()->getUID()])) {
				continue;
			}

			$userFolder = $this->rootFolder->getUserFolder($mount->getUser()->getUID());
			$result[$mount->getUser()->getUID()] = $userFolder->getById($file->getId());
		}

		return $result;
	}

	private function formatPermissions(string $type, int $permissions): string {
		if ($permissions == Constants::PERMISSION_ALL || ($type === 'file' && $permissions == (Constants::PERMISSION_ALL - Constants::PERMISSION_CREATE))) {
			return "full permissions";
		}

		$perms = [];
		$allPerms = [Constants::PERMISSION_READ => "read", Constants::PERMISSION_UPDATE => "update", Constants::PERMISSION_CREATE => "create", Constants::PERMISSION_DELETE => "delete", Constants::PERMISSION_SHARE => "share"];
		foreach ($allPerms as $perm => $name) {
			if (($permissions & $perm) === $perm) {
				$perms[] = $name;
			}
		}

		return implode(", ", $perms);
	}

	private function formatMountType(IMountPoint $mountPoint): string {
		if ($mountPoint->getStorage()->instanceOfStorage(IHomeStorage::class)) {
			return "home storage";
		} else if ($mountPoint instanceof SharedMount) {
			$share = $mountPoint->getShare();
			$shares = $mountPoint->getGroupedShares();
			$sharedBy = array_map(function(IShare $share) {
				$shareType = $this->formatShareType($share);
				if ($shareType) {
					return $share->getSharedBy() . " (via " . $shareType . " " . $share->getSharedWith() . ")";
				} else {
					return $share->getSharedBy();
				}
			}, $shares);
			$description = "shared by " . implode(', ', $sharedBy);
			if ($share->getSharedBy() !== $share->getShareOwner()) {
				$description .= " owned by " . $share->getShareOwner();
			}
			return $description;
		} else if ($mountPoint instanceof GroupMountPoint) {
			return "groupfolder " . $mountPoint->getFolderId();
		} else if ($mountPoint instanceof ExternalMountPoint) {
			return "external storage " . $mountPoint->getStorageConfig()->getId();
		} else if ($mountPoint instanceof CircleMount) {
			return "circle";
		}
		return get_class($mountPoint);
	}

	private function formatShareType(IShare $share): ?string {
		switch ($share->getShareType()) {
			case IShare::TYPE_GROUP:
				return "group";
			case IShare::TYPE_CIRCLE:
				return "circle";
			case IShare::TYPE_DECK:
				return "deck";
			case IShare::TYPE_ROOM:
				return "room";
			default:
				return null;
		}
	}

	private function storageDetails(IMountPoint $mountPoint, Node $node, OutputInterface $output) {
		$storage = $mountPoint->getStorage();
		if (!$mountPoint->getStorage()->instanceOfStorage(IHomeStorage::class)) {
			$output->writeln("  mounted at: " . $mountPoint->getMountPoint());
		}
		if ($storage->instanceOfStorage(ObjectStoreStorage::class)) {
			/** @var ObjectStoreStorage $storage */
			$objectStoreId = $storage->getObjectStore()->getStorageId();
			$parts = explode(':', $objectStoreId);
			$bucket = array_pop($parts);
			$output->writeln("  bucket: " . $bucket);
			if ($node instanceof \OC\Files\Node\File) {
				$output->writeln("  object id: " . $storage->getURN($node->getId()));
				try {
					$fh = $node->fopen('r');
					if (!$fh) {
						throw new NotFoundException();
					}
					$stat = fstat($fh);
					fclose($fh);
					if ($stat['size'] !== $node->getSize()) {
						$output->writeln("  <error>warning: object had a size of " . $stat['size'] . " but cache entry has a size of " . $node->getSize() . "</error>. This should have been automatically repaired");
					}
			} catch (\Exception $e) {
					$output->writeln("  <error>warning: object not found in bucket</error>");
				}
			}
		} else {
			if (!$storage->file_exists($node->getInternalPath())) {
				$output->writeln("  <error>warning: file not found in storage</error>");
			}
		}
		if ($mountPoint instanceof ExternalMountPoint) {
			$storageConfig = $mountPoint->getStorageConfig();
			$output->writeln("  external storage id: " . $storageConfig->getId());
			$output->writeln("  external type: " . $storageConfig->getBackend()->getText());
		} else if ($mountPoint instanceof GroupMountPoint) {
			$output->writeln("  groupfolder id: " . $mountPoint->getFolderId());
		}
	}
}
