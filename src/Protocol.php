<?php

namespace Zion\WordPressLicense;

final class Protocol
{
    public const VERSION = '1.0';

    public const MINIMUM_SDK_VERSION = '0.2.0';

    public const HEADER = 'X-Zion-Protocol-Version';

    private function __construct() {}
}
