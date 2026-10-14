<?php

namespace App\Support\Demo;

use RuntimeException;

/** Only curated, non-sensitive setup messages may be printed by the command. */
class DemoSetupException extends RuntimeException {}
