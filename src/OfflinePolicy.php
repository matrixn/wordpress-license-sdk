<?php

namespace Zion\WordPressLicense;

enum OfflinePolicy: string
{
    case Lenient = 'lenient';
    case Strict = 'strict';
}
