BKG Compressor
==============

This is a standalone PHP data compression/decompression utility that does not require the use of any external PHP libraries.
This was developed to include with a single install file that grabbed the install package and uncompressed it.

General Notes
-------------

This Class compresses a specified directory or file using the byte-pair algorithm described here:
[http://en.wikipedia.org/wiki/Byte_pair_encoding](http://en.wikipedia.org/wiki/Byte_pair_encoding)

Compression
-----------

Compression is around 2:1.

Caveats
-------

* Does not include the specified input directory.  This is by design.
* So if you send in the directory C:/Project, the Project directory will not be included.  The package will begin with the first file or directory inside the Project directory.
* Compression is best done from the command line.  If the compression freezes from the browser, it will peg your CPU.
* Testing revealed that files of 50MB or larger would hang the process.

Usage
-----
Usage:

    $bkg = new BKG();
    $bkg->compress("absolute path to directory", "output.pkg", "comma separated list of exclusions");
    $bkg->inflate("output.pkg");

* A single wildcard at the beginning of the word in the exclusions list may be used to signify that you want to exclude the Name wherever it is found.  Otherwise, you MUST use the full relative path of the file you want to exclude.

Examples
--------

    $c = new BKG();
    $c->compress("C:/Projects/", "blog.pkg", ".git, *_notes");
    $c->inflate("blog.pkg");

Package Format (if you want to build your own uncompressor)
-----------------------------------------------------------

A pointer should be used and moved the number of bytes you have read from as you process the compressed data.
A Content variable should be created that you can add to while searching and replacing data and running through the series of compression passes.

1. Type						2 bytes 0a00 = File; 0a01 = Directory
2. Length of Filename		2 bytes
3. Filename					Variable length specified by Length of Filename
4. If type is 0a01, create the directory with Filename. Return to step 1. Otherwise, if type is 0a00, continue to get file content
5. Content Size				4 bytes
6. Content					Variable length specified by Content Size
7. Number of Dictionaries	1 byte
8. Dictionary Length		2 bytes
9. Dictionary Content		Variable Length specified by Dictionary Length
10. At this point the Dictionary Content should be broken up into 3 byte sections.  The 1st byte is the byte that is in the Content, the 2nd byte is the byte that should replace it. Convert from Bit value to Ascii. Do a search and replace for all occurences of the 1st byte with the second byte. Hold the Content.
11. If the Number of Dictionaries is greater than 1, return to step 8.
12. Write the file with Filename
13. Return to step 1.

Continue to run through the data until your pointer reaches the end of the file.

License & Credits
-----------------

BKG Compressor

This software is released under the GNU General Public License
[http://www.gnu.org/copyleft/gpl.html](http://www.gnu.org/copyleft/gpl.html)
Leave this header in tact when using software.

Developed by Senica Gonzalez senica@gmail.com
[Allebrum, LLC www.allebrum.com](http://www.allebrum.com)
