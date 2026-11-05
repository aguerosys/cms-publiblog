# Servidor SFTP de prueba (solo desarrollo)

Simula el servidor de un cliente: un usuario SFTP **confinado a una carpeta** (como el usuario ligado a `/blog/`), con un comportamiento estricto como OpenSSH: `RENAME` falla si el destino existe, así se ejercita el plan B de «sobrescribir». Deja un registro de operaciones en `sftp-ops.log`.

```bash
cd tools/sftp-test-server && npm install
ssh-keygen -t ed25519 -N "" -f host_key      # clave del servidor (su huella es la que confirma el CMS)
ssh-keygen -t ed25519 -N "" -f client_key    # opcional: para probar autenticación por clave
node server.cjs ../../demoblog/blog 2222 host_key client_key.pub
```

Alta del blog en el panel: servidor `127.0.0.1`, puerto `2222`, usuario `blog`, contraseña `pw-secreto` (o pega `client_key` como clave privada), directorio remoto `/`, URL base `http://localhost:8081`. Después «Probar conexión» → comparar la huella con `ssh-keygen -lf host_key.pub -E sha256` → confirmar.

Para simular un cambio de huella, reinicia el servidor con otra `host_key`.
