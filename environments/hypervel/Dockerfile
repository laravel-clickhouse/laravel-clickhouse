# Runner image for the Hypervel bridge test suite — the same base and
# extension setup as the hypervel job in .github/workflows/tests.yml.
FROM phpswoole/swoole:6.2-php8.4

# hypervel/components declares intl as a platform requirement; Swoole short
# names must be off or Hypervel's Application refuses to boot.
RUN apt-get update -qq \
    && apt-get install -y -qq libicu-dev > /dev/null \
    && docker-php-ext-install -j"$(nproc)" intl pcntl > /dev/null \
    && echo "swoole.use_shortname=Off" > /usr/local/etc/php/conf.d/99-swoole-shortname.ini

WORKDIR /package
