// Servidor SFTP de prueba, confinado a una carpeta (como un usuario con chroot a /blog/).
// - Autenticación por contraseña (blog / pw-secreto) o por clave pública (client_key.pub).
// - RENAME falla si el destino existe (igual que OpenSSH sin posix-rename): fuerza el plan B de "sobrescribir".
// - Escribe un registro de operaciones en sftp-ops.log para comprobar qué hizo el CMS.
const { Server, utils } = require('ssh2');
const fs = require('fs');
const path = require('path');

const [, , ROOT, PORT, HOSTKEY, CLIENTPUB] = process.argv;
const root = path.resolve(ROOT);
const { STATUS_CODE, flagsToString } = utils.sftp;
const hostKey = fs.readFileSync(HOSTKEY);
const clientPub = CLIENTPUB && fs.existsSync(CLIENTPUB) ? utils.parseKey(fs.readFileSync(CLIENTPUB)) : null;
const log = (m) => fs.appendFileSync(path.join(__dirname, 'sftp-ops.log'), m + '\n');

// Todo lo que pide el cliente se interpreta dentro de ROOT (un ".." no sale de ahí).
const real = (p) => {
    const clean = path.posix.normalize('/' + String(p).replace(/\\/g, '/'));
    return path.join(root, clean);
};
const attrsOf = (st) => ({ mode: st.mode, uid: 0, gid: 0, size: st.size, atime: Math.floor(st.atimeMs / 1000), mtime: Math.floor(st.mtimeMs / 1000) });
const err = (e) => { log('ERR ' + e.code + ' ' + e.message); return (e.code === 'ENOENT' ? STATUS_CODE.NO_SUCH_FILE : e.code === 'EACCES' || e.code === 'EPERM' ? STATUS_CODE.PERMISSION_DENIED : STATUS_CODE.FAILURE); };

new Server({ hostKeys: [hostKey] }, (client) => {
    client.on('authentication', (ctx) => {
        if (ctx.method === 'password' && ctx.username === 'blog' && ctx.password === 'pw-secreto') return ctx.accept();
        if (ctx.method === 'publickey' && clientPub && ctx.username === 'blog' && ctx.key.algo === clientPub.type) {
            const ok = Buffer.compare(ctx.key.data, clientPub.getPublicSSH()) === 0;
            if (ok && (!ctx.signature || clientPub.verify(ctx.blob, ctx.signature, ctx.hashAlgo) === true)) return ctx.accept();
        }
        log('AUTH-REJECT ' + ctx.method);
        ctx.reject(['password', 'publickey']);
    });

    client.on('ready', () => {
        client.on('session', (accept) => {
            const session = accept();
            session.on('sftp', (accept) => {
                const sftp = accept();
                const handles = new Map();
                let next = 1;
                const newHandle = (v) => { const id = next++; handles.set(id, v); const b = Buffer.alloc(4); b.writeUInt32BE(id); return b; };
                const get = (b) => handles.get(b.readUInt32BE(0));

                sftp.on('REALPATH', (id, p) => {
                    const clean = path.posix.normalize('/' + p).replace(/\/$/, '') || '/';
                    sftp.name(id, [{ filename: clean, longname: clean, attrs: {} }]);
                });
                const stat = (id, p) => { try { sftp.attrs(id, attrsOf(fs.statSync(real(p)))); } catch (e) { sftp.status(id, err(e)); } };
                sftp.on('STAT', stat);
                sftp.on('LSTAT', stat);
                sftp.on('FSTAT', (id, h) => { try { sftp.attrs(id, attrsOf(fs.fstatSync(get(h).fd))); } catch (e) { sftp.status(id, err(e)); } });
                sftp.on('SETSTAT', (id) => sftp.status(id, STATUS_CODE.OK));
                sftp.on('FSETSTAT', (id) => sftp.status(id, STATUS_CODE.OK));

                sftp.on('OPENDIR', (id, p) => {
                    try { const list = fs.readdirSync(real(p)); sftp.handle(id, newHandle({ list, done: false, dir: real(p) })); }
                    catch (e) { sftp.status(id, err(e)); }
                });
                sftp.on('READDIR', (id, h) => {
                    const d = get(h);
                    if (d.done) return sftp.status(id, STATUS_CODE.EOF);
                    d.done = true;
                    sftp.name(id, d.list.map((f) => ({ filename: f, longname: f, attrs: attrsOf(fs.statSync(path.join(d.dir, f))) })));
                });

                sftp.on('OPEN', (id, filename, flags) => { 
                    try {
                        const fd = fs.openSync(real(filename), flagsToString(flags) || 'r');
                        log('OPEN ' + filename + ' ' + flagsToString(flags));
                        sftp.handle(id, newHandle({ fd }));
                    } catch (e) { sftp.status(id, err(e)); }
                });
                sftp.on('READ', (id, h, offset, length) => {
                    try {
                        const buf = Buffer.alloc(length);
                        const n = fs.readSync(get(h).fd, buf, 0, length, offset);
                        n === 0 ? sftp.status(id, STATUS_CODE.EOF) : sftp.data(id, buf.subarray(0, n));
                    } catch (e) { sftp.status(id, err(e)); }
                });
                sftp.on('WRITE', (id, h, offset, data) => {
                    try { fs.writeSync(get(h).fd, data, 0, data.length, offset); sftp.status(id, STATUS_CODE.OK); }
                    catch (e) { sftp.status(id, err(e)); }
                });
                sftp.on('CLOSE', (id, h) => {
                    const v = get(h);
                    if (v && v.fd !== undefined) fs.closeSync(v.fd);
                    handles.delete(h.readUInt32BE(0));
                    sftp.status(id, STATUS_CODE.OK);
                });
                sftp.on('REMOVE', (id, p) => { try { fs.unlinkSync(real(p)); log('REMOVE ' + p); sftp.status(id, STATUS_CODE.OK); } catch (e) { sftp.status(id, err(e)); } });
                sftp.on('MKDIR', (id, p) => { try { fs.mkdirSync(real(p)); log('MKDIR ' + p); sftp.status(id, STATUS_CODE.OK); } catch (e) { sftp.status(id, err(e)); } });
                sftp.on('RMDIR', (id, p) => { try { fs.rmdirSync(real(p)); sftp.status(id, STATUS_CODE.OK); } catch (e) { sftp.status(id, err(e)); } });
                sftp.on('RENAME', (id, from, to) => {
                    if (fs.existsSync(real(to))) { log('RENAME-REFUSED ' + from + ' -> ' + to + ' (destino existe)'); return sftp.status(id, STATUS_CODE.FAILURE); }
                    try { fs.renameSync(real(from), real(to)); log('RENAME ' + from + ' -> ' + to); sftp.status(id, STATUS_CODE.OK); }
                    catch (e) { sftp.status(id, err(e)); }
                });
            });
        });
    });
    client.on('error', () => {});
}).listen(Number(PORT), '127.0.0.1', () => console.log('sftp listo en 127.0.0.1:' + PORT + ' root=' + root));
