# proyecto_saw

Repositorio de archivos **colectivo** con intercambio **entre máquinas (P2P, tipo torrent)**.
Proyecto final de la materia de ingeniería de software.

## Qué corrige esta versión

| Antes | Ahora |
|---|---|
| La lista de archivos vivía en `localStorage` del navegador: al cambiar de máquina desaparecía | El registro vive en la base de datos (`tabla archivos`): **permanencia real**, visible desde cualquier máquina |
| Rutas rotas (`/funciones/carga.php`, `/proyecto/vendor/autoload.php`) | Rutas relativas y autoload opcional |
| Contraseña de correo expuesta en el código | Credenciales solo por variables de entorno |
| Registro escribía en una tabla (`logs`) que el login no leía | Un solo modelo: tabla `usuarios` |
| Sin forma de compartir entre equipos | **WebRTC P2P**: los archivos viajan directos de máquina a máquina, en chunks con verificación de hash SHA-256 |

## Cómo funciona

### Repositorio colectivo con permanencia

- Cada archivo se guarda en `uploads/` **por su hash SHA-256** (content-addressed):
  si dos personas suben el mismo contenido, existe una sola copia en disco.
- La tabla `archivos` registra hash, nombre, tamaño, quien lo subió y fecha.
- Cualquier usuario del repositorio ve y descarga todos los archivos
  (`listar.php`, `descargar.php`). Solo quien subió un archivo puede borrarlo.
- Base de datos **SQLite por defecto** (archivo `data/proyecto_saw.sqlite`,
  se crea sola al primer arranque). MySQL opcional por variables de entorno.

### Compartir entre máquinas, tipo torrent

1. Cada máquina abre la página con su usuario. El panel «Máquinas conectadas»
   muestra quién está en línea (presencia con latidos de 20 s).
2. Al pulsar **enviar P2P** en un archivo se elige la máquina destino:
   se descarga del servidor y se transmite **directo** por un canal de datos
   WebRTC, sin pasar por el servidor.
3. El archivo viaja en **chunks de 16 KiB** con control de flujo; el receptor
   verifica el **hash SHA-256** antes de aceptarlo (integridad de torrent).
4. Lo recibido se registra en `guardar.php` y **entra al repositorio
   colectivo**: quien recibe un archivo vuelve a poder compartirlo o
   descargarlo desde cualquier máquina (efecto *swarm*).

La señalización WebRTC (ofertas SDP y candidatos ICE) viaja por
`senalizar.php`, un buzón respaldado en la base de datos: no hace falta
ningún servicio extra.

## Cómo ejecutarlo

Necesitas PHP 8 con `pdo_sqlite` (incluido por defecto). En la carpeta:

```bash
php -S localhost:8000
```

Abre `http://localhost:8000/`. Se crea una sesión y la base de datos sola.

En dos máquinas distintas (o dos navegadores): entra con usuarios diferentes
y verás a cada uno en «Máquinas conectadas».

### Para compartir entre máquinas en la red local

En lugar de `localhost`, la máquina que sirve debe escuchar su IP:

```bash
php -S 0.0.0.0:8000
```

y las demás abren `http://IP-DE-LA-MAQUINA:8000/`.

## Cuentas y seguridad

- **Registro**: `register.php` → guarda en `usuarios` con `password_hash`.
- **Login en dos pasos** (`process.php`): contraseña correcta → token de 64 hex
  → verificación → sesión iniciada. Con protección CSRF (`token-u`),
  límite de 5 intentos por usuario y 10 por IP, con bloqueo de 10 minutos.
- **Correo**: si defines `SAW_SMTP_USER` y `SAW_SMTP_PASS` en el entorno
  (y PHPMailer está en `vendor/` vía Composer), el token se envía por correo;
  si no, se muestra en pantalla para uso local. Nunca va en el código.

### Variables de entorno

| Variable | Uso | Por defecto |
|---|---|---|
| `SAW_DB_DRIVER` | `sqlite` o `mysql` | `sqlite` |
| `SAW_DB_DSN` | DSN PDO (MySQL) | `mysql:host=localhost;dbname=mi_base;charset=utf8mb4` |
| `SAW_DB_USER` / `SAW_DB_PASS` | credenciales MySQL | `user` / `pass` |
| `SAW_SMTP_USER` / `SAW_SMTP_PASS` | envío del token por correo | (sin correo: token en pantalla) |

## Estructura

```
index.php          Repositorio: lista, subida, descarga, borrado y P2P
login/register/    Flujo de cuenta y token
  register.php · login.php · verificar.php · sign.php · process.php
config.php         Configuración, base de datos (se auto-inicializa), sesiones
carga.php          Subida: guarda por hash y registra en la tabla archivos
listar.php         Lista completa del repositorio (colectivo)
descargar.php      Descarga por hash con el nombre original
borrado.php        Borrado (solo quien subió el archivo)
guardar.php        Registra un archivo recibido por P2P tras verificar su hash
senalizar.php      Buzón de señalización WebRTC y presencia
assets/p2p.js      Motor WebRTC: chunks de 16 KiB, hash SHA-256, swarm
assets/app.js      Interfaz del repositorio
uploads/           Archivos en disco, nombrados por su hash (con permanencia)
data/              Base de datos SQLite (se crea sola)
```

## Probar el P2P en una sola computadora

Abre dos navegadores distintos (por ejemplo Chrome y Safari, o uno en modo
privado): entra con usuarios diferentes en `http://localhost:8000/` y se verán
mutuamente. Envía un archivo por P2P: el receptor lo verifica por hash y el
archivo aparece en el repositorio para los dos.
