<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use RuntimeException;

/** A successful HTTP response contained no usable bounded model proposal. */
final class ModelProposalRejected extends RuntimeException
{
}
