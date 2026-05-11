<?php
if (! defined('ABSPATH')) {
	exit;
}

class DDB_Spots_Core_Container {
	private array $services = array();
	private array $instances = array();

	public function set(string $id, callable $factory): void {
		$this->services[$id] = $factory;
	}

	public function get(string $id): mixed {
		if (isset($this->instances[$id])) {
			return $this->instances[$id];
		}

		if (! isset($this->services[$id])) {
			throw new Exception("Service not found: {$id}");
		}

		$this->instances[$id] = ($this->services[$id])($this);
		return $this->instances[$id];
	}

	public function has(string $id): bool {
		return isset($this->services[$id]);
	}
}
