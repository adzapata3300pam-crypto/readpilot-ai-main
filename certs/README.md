# TLS CA certificates for the database connection.

Place the PEM certificate your database provider supplies here and point
DB_SSL_CA at it:

    DB_SSL_CA=/var/www/html/certs/ca.pem

Why this is necessary: PDO's MySQL DSN supports only host, port, dbname,
user, password and unix_socket. There is no sslmode keyword, so setting
DB_SSLMODE on its own is silently ignored and the connection is unencrypted.

Download the certificate from your provider:
  Aiven        Connection information -> CA certificate -> Download
  PlanetScale  not required (public CA)
  TiDB Cloud   Connection -> CA certificate -> Download

A CA certificate is public, so committing it is safe. Never commit the
private key or any password here.
