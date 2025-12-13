FROM ghcr.io/freegle/freegle-base:latest

ARG IZNIK_SERVER_BRANCH=master

ENV DEBIAN_FRONTEND=noninteractive \
	  TZ='UTZ' \
	  NOTVISIBLE="in users profile" \
	  STANDALONE=TRUE \
	  SQLHOST=percona \
	  SQLPORT=3306 \
	  SQLUSER=root \
	  SQLPASSWORD=iznik \
	  SQLDB=iznik \
	  PGSQLHOST=postgres \
	  PGSQLUSER=root \
	  PGSQLPASSWORD=iznik \
	  PGSQLDB=iznik \
	  PGSQLPORT=5432 \
	  PHEANSTALK_SERVER=beanstalkd \
	  IMAGE_DOMAIN=apiv1.localhost

# Configure xdebug to support coverage mode
RUN echo "zend_extension=xdebug.so" > /etc/php/8.1/mods-available/xdebug.ini \
    && echo "xdebug.mode=develop,coverage" >> /etc/php/8.1/mods-available/xdebug.ini \
    && phpenmod xdebug

# Configure Postfix for MailHog relay
RUN echo "postfix postfix/mailname string localhost" | debconf-set-selections \
    && echo "postfix postfix/main_mailer_type string 'Satellite system'" | debconf-set-selections \
    && echo "postfix postfix/relayhost string [mailhog]:1025" | debconf-set-selections

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    mkdir -p /var/www \
	&& cd /var/www \
	&& apt-get -o Acquire::Check-Valid-Until=false -o Acquire::Check-Date=false update \
	&& git clone https://github.com/Freegle/iznik-server.git iznik \
	&& cd iznik \
	&& git checkout ${IZNIK_SERVER_BRANCH} \
	&& cd /var/www \
  && touch iznik/standalone \
  && mkdir /var/www/iznik/spool \
  && chown www-data:www-data /var/www/iznik/spool \
  && touch /tmp/iznik.uploadlock \
  && chmod 777 /tmp/iznik.uploadlock

# SSHD
RUN mkdir /var/run/sshd \
	&& echo 'root:password' | chpasswd \
	&& sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config \
	&& sed 's@session\s*required\s*pam_loginuid.so@session optional pam_loginuid.so@g' -i /etc/pam.d/sshd \
	&& echo "export VISIBLE=now" >> /etc/profile

WORKDIR /var/www/iznik

# /etc/iznik.conf is where our config goes.
RUN cp install/iznik.conf.php /etc/iznik.conf \
    && echo secret > /etc/iznik_jwt_secret \
    && sed -ie "s/upload_max_filesize = 2M/upload_max_filesize = 8M/" /etc/php/8.1/fpm/php.ini \
    && sed -ie "s/'SQLHOST', '.*'/'SQLHOST', '$SQLHOST:$SQLPORT'/" /etc/iznik.conf \
    && sed -ie "s/'SQLHOSTS_READ', '.*'/'SQLHOSTS_READ', '$SQLHOST:$SQLPORT'/" /etc/iznik.conf \
    && sed -ie "s/'SQLHOSTS_MOD', '.*'/'SQLHOSTS_MOD', '$SQLHOST:$SQLPORT'/" /etc/iznik.conf \
    && sed -ie "s/'SQLUSER', '.*'/'SQLUSER', '$SQLUSER'/" /etc/iznik.conf \
    && sed -ie "s/'SQLPASSWORD', '.*'/'SQLPASSWORD', '$SQLPASSWORD'/" /etc/iznik.conf \
    && sed -ie "s/'SQLDB', '.*'/'SQLDB', '$PGSQLDB'/" /etc/iznik.conf \
    && sed -ie "s/'PGSQLHOST', '.*'/'PGSQLHOST', '$PGSQLHOST:$PGSQLPORT'/" /etc/iznik.conf \
    && sed -ie "s/'PGSQLUSER', '.*'/'PGSQLUSER', '$PGSQLUSER'/" /etc/iznik.conf \
    && sed -ie "s/'PGSQLPASSWORD', '.*'/'PGSQLPASSWORD', '$PGSQLPASSWORD'/" /etc/iznik.conf \
    && sed -ie "s/'PGSQLDB', '.*'/'PGSQLDB', '$PGSQLDB'/" /etc/iznik.conf \
    && sed -ie "s/'PHEANSTALK_SERVER', '.*'/'PHEANSTALK_SERVER', '$PHEANSTALK_SERVER'/" /etc/iznik.conf \
    && sed -ie "s/'IMAGE_DOMAIN', '.*'/'IMAGE_DOMAIN', '$IMAGE_DOMAIN'/" /etc/iznik.conf \
    && sed -ie "s/'IMAGE_DELIVERY', '.*'/'IMAGE_DELIVERY', '$IMAGE_DELIVERY'/" /etc/iznik.conf \
    && sed -ie "s/case 'iznik.ilovefreegle.org'/default/" /etc/iznik.conf \
    && echo "[mysql]" > ~/.my.cnf \
    && echo "host=$SQLHOST" >> ~/.my.cnf \
    && echo "user=$SQLUSER" >> ~/.my.cnf \
    && echo "password=$SQLPASSWORD" >> ~/.my.cnf \
    && echo "redis" > /etc/iznikredis

# Install composer dependencies
RUN wget https://getcomposer.org/composer-2.phar -O composer.phar \
    && php composer.phar self-update \
    && cd composer \
    && echo Y | php ../composer.phar install \
    && cd ..

# Cron jobs for background scripts (excluding messages_spatial)
RUN grep -v "message_spatial.php" install/crontab | crontab -u root -

# Tidy image
RUN rm -rf /var/lib/apt/lists/*

CMD /etc/init.d/ssh start \
  # Set up the environment we need. Putting this here means it gets reset each time we start the container.
  && cp install/nginx.conf /etc/nginx/sites-available/default \
  && /etc/init.d/nginx start \
	&& /etc/init.d/cron start \
	&& /etc/init.d/php8.1-fpm start \
	&& /etc/init.d/postfix start \

  && export LOVE_JUNK_API=`cat /run/secrets/LOVE_JUNK_API` \
  && export LOVE_JUNK_SECRET=`cat /run/secrets/LOVE_JUNK_SECRET` \
  && export PARTNER_KEY=`cat /run/secrets/PARTNER_KEY` \
  && export PARTNER_NAME=`cat /run/secrets/PARTNER_NAME` \
  && export IMAGE_DOMAIN=`cat /run/secrets/IMAGE_DOMAIN` \
  && sed -ie "s@'LOVE_JUNK_API', '.*'@'LOVE_JUNK_API', '$LOVE_JUNK_API'@" /etc/iznik.conf \
  && sed -ie "s@'LOVE_JUNK_SECRET', '.*'@'LOVE_JUNK_SECRET', '$LOVE_JUNK_SECRET'@" /etc/iznik.conf \
  && sed -ie "s@'IMAGE_DOMAIN', '.*'@'IMAGE_DOMAIN', '$IMAGE_DOMAIN'@" /etc/iznik.conf \
  # Update Google and Mapbox API keys from environment variables \
  && sed -ie "s@'GOOGLE_CLIENT_ID', '.*'@'GOOGLE_CLIENT_ID', '$GOOGLE_CLIENT_ID'@" /etc/iznik.conf \
  && sed -ie "s@'GOOGLE_CLIENT_SECRET', '.*'@'GOOGLE_CLIENT_SECRET', '$GOOGLE_CLIENT_SECRET'@" /etc/iznik.conf \
  && sed -ie "s@'GOOGLE_PUSH_KEY', '.*'@'GOOGLE_PUSH_KEY', '$GOOGLE_PUSH_KEY'@" /etc/iznik.conf \
  && sed -ie "s@'GOOGLE_VISION_KEY', '.*'@'GOOGLE_VISION_KEY', '$GOOGLE_VISION_KEY'@" /etc/iznik.conf \
  && sed -ie "s@'GOOGLE_PERSPECTIVE_KEY', '.*'@'GOOGLE_PERSPECTIVE_KEY', '$GOOGLE_PERSPECTIVE_KEY'@" /etc/iznik.conf \
  && sed -ie "s@'GOOGLE_GEMINI_API_KEY', '.*'@'GOOGLE_GEMINI_API_KEY', '$GOOGLE_GEMINI_API_KEY'@" /etc/iznik.conf \
  && sed -ie "s@'GOOGLE_PROJECT', '.*'@'GOOGLE_PROJECT', '$GOOGLE_PROJECT'@" /etc/iznik.conf \
  && sed -ie "s@'GOOGLE_APP_NAME', '.*'@'GOOGLE_APP_NAME', '$GOOGLE_APP_NAME'@" /etc/iznik.conf \
  && sed -ie "s@'MAPBOX_TOKEN', '.*'@'MAPBOX_TOKEN', '$MAPBOX_KEY'@" /etc/iznik.conf \
  && sed -ie "s@'SPAMD_HOST', '.*'@'SPAMD_HOST', '$SPAMD_HOST'@" /etc/iznik.conf \
  && sed -ie "s@'TUS_UPLOADER', \".*\"@'TUS_UPLOADER', '$TUS_UPLOADER'@" /etc/iznik.conf \
  && sed -ie "s@'IMAGE_DELIVERY', NULL@'IMAGE_DELIVERY', '$IMAGE_DELIVERY'@" /etc/iznik.conf \
  && sed -ie "s@'SMTP_HOST', '.*'@'SMTP_HOST', '$SMTP_HOST'@" /etc/iznik.conf \
  && sed -ie "s@'SMTP_PORT', [0-9]*@'SMTP_PORT', $SMTP_PORT@" /etc/iznik.conf \

	# We need to make some minor schema tweaks otherwise the schema fails to install.
  && sed -ie 's/ROW_FORMAT=DYNAMIC//g' install/schema.sql \
  && sed -ie 's/timestamp(3)/timestamp/g' install/schema.sql \
  && sed -ie 's/timestamp(6)/timestamp/g' install/schema.sql \
  && sed -ie 's/CURRENT_TIMESTAMP(3)/CURRENT_TIMESTAMP/g' install/schema.sql \
  && sed -ie 's/CURRENT_TIMESTAMP(6)/CURRENT_TIMESTAMP/g' install/schema.sql \
	&& mysql -u root -e 'CREATE DATABASE IF NOT EXISTS iznik;' \
  && mysql -u root iznik < install/schema.sql \
  && mysql -u root iznik < install/functions.sql \
  && mysql -u root iznik < install/damlevlim.sql \
  && mysql -u root -e "SET GLOBAL sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'" \
  && mysql -u root -e "use iznik;REPLACE INTO partners_keys (partner, \`key\`) VALUES ('$PARTNER_NAME', '$PARTNER_KEY');" \
  && mysql -u root -e "SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));" \
  && php scripts/cli/table_autoinc.php \
  && php scripts/cron/get_app_release_versions.php >> /tmp/iznik.get_app_release_versions.out 2>&1 \

  # Start messages_spatial loop in background and keep container alive
  && sh -c 'nohup sh -c "while true; do cd /var/www/iznik/scripts/cron && php ./message_spatial.php >> /tmp/iznik.message_spatial.out 2>&1; sleep 10; done" </dev/null >/dev/null 2>&1 & exec sleep infinity'

EXPOSE 80
