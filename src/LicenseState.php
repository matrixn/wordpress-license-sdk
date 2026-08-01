<?php

namespace Zion\WordPressLicense;

enum LicenseState: string
{
    case Unconfigured = 'unconfigured';
    case Active = 'active';
    case GracePeriod = 'grace_period';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
    case ActivationLimitReached = 'activation_limit_reached';
    case NotStarted = 'not_started';
    case Unlicensed = 'unlicensed';
    case Unreachable = 'server_unreachable';
}
