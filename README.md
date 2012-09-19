# PHP remoteStorage Server

# Installation
*NOTE*: in the `chown` line you need to use your own user account name!

    $ cd /var/www/html
    $ su -c 'mkdir php-remoteStorage'
    $ su -c 'chown fkooman.fkooman php-remoteStorage'
    $ git clone git://github.com/fkooman/php-remoteStorage.git
    $ cd php-remoteStorage

Now you can create the default configuration files, the paths will be
automatically set, permissions set and a sample Apache configuration file will
be generated and shown on the screen (see below for more information on
Apache configuration).

    $ docs/configure.sh

# Debian

    apt-get install git mysql-server libapache2-mod-php5 libapache2-mod-xsendfile
    cd /var/www
    git clone git@github.com:michielbdejong/php-remoteStorage.git
    cd php-remoteStorage


# SELinux
The install script already takes care of setting the file permissions of the
`data/` directory to allow Apache to write to the directory. 
