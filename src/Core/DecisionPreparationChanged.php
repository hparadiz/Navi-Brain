<?php

declare(strict_types=1);

namespace NaviBrain\Core;

/** Another producer may have published the reasoning job during preparation. */
final class DecisionPreparationChanged extends \RuntimeException
{
}
