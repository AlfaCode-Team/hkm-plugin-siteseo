<?php
namespace Plugins\SiteSEO\Schema;

use Plugins\SiteSEO\Interfaces\SchemaInterface;


/**
 * @package Plugins\SiteSEO
 * @since v2.0
 * @see https://git.io/phpseo
 * @license MIT
 * @copyright 2019-present Mohamed Elabhja
 */
class Thing implements SchemaInterface
{

	protected $type;
	protected $data = [];
	public $context = null;


	public function __construct(string $type, array $data = [])
	{
		if (isset($data["context"])) {
			$this->context = $data["context"];
			unset($data['context']);
		}
		$this->data = $data;
		$this->type = $type;
	}

	public function __get(string $name)
	{
		return $this->data[$name] ?? null;
	}


	public function __set(string $name, $value)
	{
		$this->data[$name] = $value;
	}

	public function jsonSerialize(): array
	{
		$data = [
			'@type' => $this->type,
		];

		if ($this->context !== null) {
			$data['@context'] = $this->context;
		}

		$json = array_merge($this->data, $data);
		ksort($json);

		return $json;
	}

	public function __toString(): string
	{
		// JSON_HEX_TAG is load-bearing, not cosmetic: without it any '<' in the
		// data reaches the page verbatim, so a node carrying '</script>' closes
		// this block early and everything after it is parsed as markup.
		// Schema::__toString() already sets it; a lone Thing must match.
		return '<script type="application/ld+json">'
			. json_encode($this, \JSON_HEX_TAG | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
			. '</script>';
	}
}
