<?php

arch('it will not use debugging functions')
    ->expect('Deinte\Peppol')
    ->not->toUse(['dd', 'dump', 'ray']);
