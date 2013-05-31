Mht to HTML
===========

A fast memory effecient PHP class to convert MHT file to HTML (and images)

Usage:
------

    require('MthToHtml.php');
    $mth = new MhtToHtml('./mthfile.mht', './output' /* output directory, default to './html' */);

    // optional, save images using images md5 file as name, around 2 times slower but can make sure there's no duplicate if image files
    // $mth->setReplaceImageName(true);

    $time = microtime(true);
    $mth->parse();
    $time = microtime(true) - $time;
    echo 'Time Used: ', $time, PHP_EOL, 'Peak Memory:', memory_get_peak_usage();
