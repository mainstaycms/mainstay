<?php

namespace Mainstay\Database;

use InvalidArgumentException;

/*
 | A call the query layer does not take, as its caller wrote it: an operator
 | a where has no use for, a field there is none of, a draft named twice.
 | Apart from what the layer finds stored and cannot read, which is the
 | site's to mend, so the API answers this one to its caller and reports
 | the other.
 */
class BadQuery extends InvalidArgumentException {}
