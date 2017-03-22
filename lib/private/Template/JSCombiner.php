<?php
/**
 * @copyright 2017, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OC\Template;

use OCP\ICache;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IURLGenerator;

class JSCombiner {

	/** @var IAppData */
	protected $appData;

	/** @var IURLGenerator */
	protected $urlGenerator;

	/** @var ICache */
	protected $depsCache;

	/**
	 * JSCombiner constructor.
	 *
	 * @param IAppData $appData
	 * @param IURLGenerator $urlGenerator
	 * @param ICache $depsCache
	 */
	public function __construct(IAppData $appData,
								IURLGenerator $urlGenerator,
								ICache $depsCache) {
		$this->appData = $appData;
		$this->urlGenerator = $urlGenerator;
		$this->depsCache = $depsCache;
	}

	/**
	 * @param string $root
	 * @param string $file
	 * @param string $app
	 * @return bool
	 */
	public function process($root, $file, $app) {
		$path = explode('/', $root . '/' . $file);

		$fileName = array_pop($path);
		$path = implode('/', $path);

		try {
			$folder = $this->appData->getFolder($app);
		} catch(NotFoundException $e) {
			// creating css appdata folder
			$folder = $this->appData->newFolder($app);
		}

		if($this->isCached($fileName, $folder)) {
			return true;
		}
		return $this->cache($path, $fileName, $folder);
	}

	/**
	 * @param string $fileName
	 * @param ISimpleFolder $folder
	 * @return bool
	 */
	protected function isCached($fileName, ISimpleFolder $folder) {
		$fileName = str_replace('.json', '.js', $fileName) . '.deps';
		try {
			$deps = $this->depsCache->get($folder->getName() . '-' . $fileName);
			if ($deps === null) {
				$depFile = $folder->getFile($fileName);
				$deps = $depFile->getContent();
				$this->depsCache->set($folder->getName() . '-' . $fileName, $deps);
			}
			$deps = json_decode($deps, true);

			foreach ($deps as $file=>$mtime) {
				if (!file_exists($file) || filemtime($file) > $mtime) {
					return false;
				}
			}

			return true;
		} catch(NotFoundException $e) {
			return false;
		}
	}

	/**
	 * @param string $path
	 * @param string $fileName
	 * @param ISimpleFolder $folder
	 * @return bool
	 */
	protected function cache($path, $fileName, ISimpleFolder $folder) {
		$deps = [];
		$fullPath = $path . '/' . $fileName;
		$data = json_decode(file_get_contents($fullPath));
		$deps[$fullPath] = filemtime($fullPath);

		$res = '';
		foreach ($data as $file) {
			$filePath = $path . '/' . $file;

			if (is_file($filePath)) {
				$res .= file_get_contents($filePath);
				$res .= PHP_EOL . PHP_EOL;
				$deps[$filePath] = filemtime($filePath);
			}
		}

		$fileName = str_replace('.json', '.js', $fileName);
		try {
			$cachedfile = $folder->getFile($fileName);
		} catch(NotFoundException $e) {
			$cachedfile = $folder->newFile($fileName);
		}

		$depFileName = $fileName . '.deps';
		try {
			$depFile = $folder->getFile($depFileName);
		} catch (NotFoundException $e) {
			$depFile = $folder->newFile($depFileName);
		}

		try {
			$cachedfile->putContent($res);
			$depFile->putContent(json_encode($deps));
			return true;
		} catch (NotPermittedException $e) {
			return false;
		}
	}

	/**
	 * @param string $appName
	 * @param string $fileName
	 * @return string
	 */
	public function getCachedJS($appName, $fileName) {
		$tmpfileLoc = explode('/', $fileName);
		$fileName = array_pop($tmpfileLoc);
		$fileName = str_replace('.json', '.js', $fileName);

		return substr($this->urlGenerator->linkToRoute('core.Js.getJs', array('fileName' => $fileName, 'appName' => $appName)), strlen(\OC::$WEBROOT) + 1);
	}
}
