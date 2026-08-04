# RustDesk auto-hospedado + Painel de Suporte

Stack completa para oferecer **suporte remoto** com a sua própria marca, sem
depender da infraestrutura pública da RustDesk:

- **Servidor RustDesk** (`hbbs` + `hbbr`) em Docker — faz o registro de ID, a
  travessia de NAT (hole punching) e o relay quando a conexão direta P2P falha.
- **Painel de gestão** em PHP (`panel/`) — dashboard web para administrar
  operadores, dispositivos, sessões e auditoria. Também expõe a API que os apps
  clientes usam para login/política.
- **Cliente RustDesk customizado** — o fork
  [**comunitariogpt-blip/rustdesk**](https://github.com/comunitariogpt-blip/rustdesk),
  com a sua logo e o seu domínio embutidos, compilado via
  **GitHub Actions → Release** (veja a [seção 4.2](#42-cliente-customizado-recompilar-o-fork)).

```text
┌─────────────┐   21115-21119    ┌──────────────────────────┐
│  Cliente /  │◄────────────────►│  Servidor RustDesk        │
│  Operador   │   (P2P / relay)  │  hbbs + hbbr  (Docker)    │
└─────┬───────┘                  └──────────────────────────┘
      │  HTTPS (API /api/*)
      ▼
┌──────────────────────────┐        ┌──────────────┐
│  Painel  (Apache + PHP)  │◄──────►│ MySQL/MariaDB │
│  panel/public/           │        └──────────────┘
└──────────────────────────┘
      ▲  HTTPS (navegador)
      │
   Administrador
```

> **Onde rodar:** o servidor RustDesk usa `network_mode: host`, que é um recurso
> **exclusivo do Linux**. Para produção, hospede o backend num **servidor Linux**
> (Ubuntu, etc.). Windows e macOS servem para teste/desenvolvimento — veja o aviso
> na [seção 1](#1-backend-rustdesk-docker).

---

## Sumário

1. [Backend RustDesk (Docker)](#1-backend-rustdesk-docker)
2. [Banco de dados](#2-banco-de-dados)
3. [Painel / Frontend (Apache + PHP)](#3-painel--frontend-apache--php)
4. [Sua própria logo / personalização](#4-sua-própria-logo--personalização)
5. [Segurança — o que NUNCA versionar](#5-segurança--o-que-nunca-versionar)
6. [Apêndice: comandos rápidos e troubleshooting](#6-apêndice-comandos-rápidos-e-troubleshooting)

### Pré-requisitos gerais

- **Docker** + **Docker Compose** (para o backend).
- **MySQL** ou **MariaDB** (para o painel).
- **Apache** + **PHP 7.4+** com as extensões `pdo_mysql` e `mbstring` (para o painel).
- Um **domínio** (ou IP público fixo) apontando para o servidor, e as portas
  `21115–21119` liberadas no firewall.

---

## 1. Backend RustDesk (Docker)

O serviço é definido em [`docker-compose.yml`](docker-compose.yml): dois
contêineres (`hbbs` e `hbbr`) usando a imagem oficial `rustdesk/rustdesk-server`,
com os dados persistidos em `./data`.

### 1.1 Instalar o Docker

**Ubuntu / Linux**

```bash
# Forma mais simples (instala Docker Engine + plugin Compose)
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"   # opcional: usar docker sem sudo (relogue depois)
```

**Windows**

1. Instale o **[Docker Desktop](https://www.docker.com/products/docker-desktop/)**.
2. Ele exige o **WSL2** habilitado (o instalador guia o processo).
3. Abra o PowerShell e confirme: `docker --version` e `docker compose version`.

**macOS**

1. Instale o **Docker Desktop** (escolha a versão Intel ou Apple Silicon).
2. Confirme no Terminal: `docker --version` e `docker compose version`.

### 1.2 Subir os serviços

A partir da raiz do projeto:

```bash
docker compose up -d
```

- No **primeiro start**, o servidor gera automaticamente a pasta `./data` com o
  par de chaves do servidor (`id_ed25519` = **privada**, `id_ed25519.pub` =
  pública) e o banco interno `db_v2.sqlite3`.
- Libere no firewall as portas: `21115/tcp`, `21116/tcp+udp`, `21117/tcp`,
  `21118/tcp`, `21119/tcp`.

### 1.3 Obter os dados de conexão dos clientes

Se você distribuir o [cliente customizado](#42-cliente-customizado-recompilar-o-fork),
estes valores já saem **embutidos no executável** e o usuário final não configura
nada. Para configurar manualmente um RustDesk padrão (ou para teste):

| Campo        | Valor                                                          |
|--------------|----------------------------------------------------------------|
| ID Server    | o **IP público ou domínio** deste servidor                     |
| Relay Server | *(deixar em branco — o ID Server informa)*                     |
| API Server   | *(deixar em branco; ou a URL do painel, se for usar a API)*    |
| Key          | conteúdo de `data/id_ed25519.pub` (**chave pública**)          |

Para ler a chave pública:

```bash
# Linux / macOS
cat data/id_ed25519.pub
```
```powershell
# Windows (PowerShell)
type data\id_ed25519.pub
```

> A **chave pública** pode ser distribuída — ela é necessária em todos os clientes
> para a criptografia ponta-a-ponta. A **chave privada** (`data/id_ed25519`)
> **NUNCA** deve sair do servidor.

### 1.4 Operação

```bash
docker compose ps             # status dos contêineres
docker compose logs -f hbbs   # logs do servidor de ID
docker compose logs -f hbbr   # logs do relay
docker compose restart        # reiniciar
docker compose down           # parar
docker compose up -d          # iniciar
docker compose pull && docker compose up -d   # atualizar para a última versão
```

> **⚠️ Windows / macOS:** o `network_mode: host` do `docker-compose.yml` só
> funciona plenamente no **Linux**. No Docker Desktop (Win/macOS) o host networking
> é limitado e o hole punching/NAT pode não funcionar. Para esses ambientes, use
> apenas para teste e publique as portas explicitamente (`ports:`) em vez de
> `network_mode: host`. **Em produção, rode o backend num servidor Linux.**

---

## 2. Banco de dados

O painel usa **MySQL/MariaDB**. O schema está em
[`panel/schema.sql`](panel/schema.sql) (tabelas `admins`, `operators`,
`operator_tokens`, `devices`, `connections`, `login_audit`, `settings`).

### 2.1 Instalar

| SO            | Comando / pacote                                                        |
|---------------|-------------------------------------------------------------------------|
| Ubuntu/Linux  | `sudo apt install mariadb-server`                                       |
| Windows       | MySQL Installer / MariaDB MSI — ou já vem no **XAMPP** (veja a seção 3)  |
| macOS         | `brew install mariadb` (ou `brew install mysql`) e `brew services start mariadb` |

### 2.2 Criar o banco e o usuário

Entre no console (`sudo mysql` no Linux, `mysql -u root -p` nos demais) e rode —
**troque a senha**:

```sql
CREATE DATABASE rustdesk_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rustdesk_panel'@'localhost' IDENTIFIED BY 'TROQUE_ESTA_SENHA';
GRANT ALL PRIVILEGES ON rustdesk_panel.* TO 'rustdesk_panel'@'localhost';
FLUSH PRIVILEGES;
```

### 2.3 Importar o schema

```bash
mysql -u rustdesk_panel -p rustdesk_panel < panel/schema.sql
```

Anote `host`, `porta`, `nome do banco`, `usuário` e `senha` — eles vão para o
`secrets.env` na próxima seção.

---

## 3. Painel / Frontend (Apache + PHP)

O painel é PHP puro (sem framework). O **DocumentRoot do Apache deve apontar para
`panel/public/`** — esse diretório tem um `index.php` (front controller) e um
`.htaccess` que faz o rewrite de todas as rotas. Os demais diretórios (`panel/src`,
`panel/config.php`, `panel/secrets.env`) ficam **fora** da raiz web, protegidos.

### 3.1 Instalar Apache + PHP

**Ubuntu / Linux**

```bash
sudo apt install apache2 php libapache2-mod-php php-mysql php-mbstring
sudo a2enmod rewrite
sudo systemctl reload apache2
```

**Windows** — o caminho mais simples é o **[XAMPP](https://www.apachefriends.org/)**
(traz Apache + PHP + MariaDB juntos). Depois de instalar:

- Habilite a extensão `pdo_mysql` no `php.ini` (geralmente já vem ativa).
- O `mod_rewrite` já costuma vir habilitado no `httpd.conf` do XAMPP.

**macOS** — use o **Homebrew**:

```bash
brew install httpd php          # Apache + PHP
brew services start httpd
```

(O Apache embutido do macOS não traz mais PHP nas versões recentes; prefira o do
Homebrew.)

### 3.2 Configurar os segredos do painel

Copie o modelo e preencha com os seus valores (banco da seção 2, marca, domínio):

```bash
cd panel
cp secrets.env.example secrets.env
chmod 600 secrets.env          # Linux/macOS — restringe a leitura
```

Edite `panel/secrets.env`:

```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=rustdesk_panel
DB_USER=rustdesk_panel
DB_PASS=a-senha-que-voce-criou
APP_KEY=<gere com: openssl rand -hex 32>
PANEL_BRAND=Meu Suporte
PANEL_TITLE=Meu Suporte · Suporte Remoto
API_SERVER_URL=https://rd.exemplo.com
ID_SERVER=                     # IP/host do servidor de ID (opcional; só exibido no painel)
DISPLAY_TZ=America/Sao_Paulo
```

> O `secrets.env` **não é versionado** (está no `.gitignore`). O
> [`panel/config.php`](panel/config.php) lê esses valores; sem o arquivo, ele cai
> em defaults genéricos.

### 3.3 VirtualHost mínimo

Troque `rd.exemplo.com` pelo seu domínio e `/CAMINHO/PARA/rustdesk` pelo caminho
real do projeto.

```apache
<VirtualHost *:80>
    ServerName rd.exemplo.com
    DocumentRoot "/CAMINHO/PARA/rustdesk/panel/public"

    <Directory "/CAMINHO/PARA/rustdesk/panel/public">
        AllowOverride All        # necessário para o .htaccess / mod_rewrite
        Require all granted
    </Directory>
</VirtualHost>
```

**Onde colocar esse bloco:**

| SO            | Arquivo / passos                                                                                                   |
|---------------|--------------------------------------------------------------------------------------------------------------------|
| Ubuntu/Linux  | Crie `/etc/apache2/sites-available/rustdesk.conf`, depois `sudo a2ensite rustdesk && sudo systemctl reload apache2` |
| Windows (XAMPP)| Adicione em `C:\xampp\apache\conf\extra\httpd-vhosts.conf` e reinicie o Apache pelo painel do XAMPP                |
| macOS (Homebrew)| Adicione em `/opt/homebrew/etc/httpd/extra/httpd-vhosts.conf` (Apple Silicon) ou `/usr/local/etc/httpd/...` (Intel) e `brew services restart httpd` |

**URL utilizável:** aponte o domínio para o servidor via DNS. Para teste local,
adicione ao arquivo `hosts` (`/etc/hosts` no Linux/macOS;
`C:\Windows\System32\drivers\etc\hosts` no Windows):

```text
127.0.0.1   rd.exemplo.com
```

**HTTPS (recomendado em produção, Ubuntu):**

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d rd.exemplo.com
```

### 3.4 Criar o primeiro administrador

```bash
php panel/seed_admin.php <usuario> [senha]
```

Se a senha for omitida, uma aleatória é gerada e exibida no terminal. Esse script
usa o `secrets.env` para acessar o banco, então configure-o antes (seção 3.2).

### 3.5 Acessar

Abra `https://rd.exemplo.com` (ou `http://...` em teste), faça login com o admin
criado e o painel estará no ar com a sua marca.

> **Exigir login dos operadores:** em **Configurações → Exigir login** você
> define se o app cliente obriga o operador a autenticar (com os usuários da
> tela *Operadores*) antes de qualquer conexão. A opção nasce **desligada**;
> ao ligar, a política chega aos clientes já abertos em ~15 s, sem recompilar.
> Requer o [cliente customizado](#42-cliente-customizado-recompilar-o-fork)
> com `API_SERVER` preenchido.

---

## 4. Sua própria logo / personalização

A marca aparece em **dois lugares**: o **painel web** (texto/cor) e o **app cliente**
(ícones embutidos no executável).

### 4.1 Marca do painel

- **Nome e título:** defina `PANEL_BRAND` e `PANEL_TITLE` no `panel/secrets.env`
  (seção 3.2). Aparecem no login, no título da aba e na barra lateral.
- **Cor de destaque:** edite a variável `--brand` no topo de
  [`panel/public/assets/app.css`](panel/public/assets/app.css):

  ```css
  :root { --brand: #00AEEF; }   /* troque pela sua cor */
  ```

- **Favicon (opcional):** coloque um `favicon.ico` em `panel/public/` e referencie-o
  no `<head>` do layout em `panel/src/admin.php`.

### 4.2 Cliente customizado (recompilar o fork)

O cliente é compilado a partir do fork
[**comunitariogpt-blip/rustdesk**](https://github.com/comunitariogpt-blip/rustdesk).
A logo e o endereço do servidor ficam **compilados dentro do executável** — não
dá para trocar sem recompilar. Guia completo no próprio fork:
[`docs/CUSTOM-SERVER-PTBR.md`](https://github.com/comunitariogpt-blip/rustdesk/blob/master/docs/CUSTOM-SERVER-PTBR.md).
Resumo do processo:

1. **Faça um fork** de
   [comunitariogpt-blip/rustdesk](https://github.com/comunitariogpt-blip/rustdesk)
   na sua conta do GitHub (é dele que o GitHub Actions gera os instaladores).
2. **Configure o seu servidor em um único arquivo**: edite o
   [`custom.env`](https://github.com/comunitariogpt-blip/rustdesk/blob/master/custom.env)
   na raiz do fork com os valores desta stack:

   ```ini
   RENDEZVOUS_SERVER=rd.exemplo.com                    # IP/domínio do hbbs (seção 1)
   RS_PUB_KEY=<conteúdo de data/id_ed25519.pub>        # chave pública (seção 1.3)
   API_SERVER=https://rd.exemplo.com                   # URL deste painel (seção 3)
   ```

   O build aplica esses valores automaticamente em todas as plataformas
   (Windows, Linux, macOS, Android, iOS) — não é preciso editar código.
3. **Substitua os ícones** pelos seus, nos mesmos nomes/tamanhos que estão em
   [`brand_suporte/`](brand_suporte/) (use estes como referência de formato),
   dentro de `res/` no fork (e `flutter/android/.../mipmap-*` para Android):
   - Windows: `icon.ico`, `tray-icon.ico`
   - PNGs multi-resolução: `icon_16.png` … `icon_512.png`, `icon_128x128@2x.png`
   - macOS: `AppIcon.icns`, `mac-tray-*.png`
   - Android: `android/mipmap-*/ic_launcher*.png`
4. **Dispare o build**: commit + push das alterações e depois use
   **Actions → Flutter Tag Build → Run workflow**, informando o nome da tag
   (ex.: `v1.4.7-1`). Ao final, **baixe os executáveis** na aba **Releases** do
   fork. Nenhum workflow do fork roda sozinho — todos são manuais.
5. **Publique os executáveis** em `panel/public/dist/` (esse diretório é ignorado
   pelo Git; é de onde o painel disponibiliza os downloads).

> **O release traz dois executáveis portable** (sem instalação), gerados no
> mesmo build:
> `rustdesk-<versão>-x86_64-operador.exe` — para quem presta o suporte (campo de
> ID remoto + login, não expõe o próprio ID) — e
> `rustdesk-<versão>-x86_64-cliente.exe` — para quem recebe o suporte (mostra
> apenas ID e senha, sem login e sem campo de conexão).

> **Se todos os passos `Publish ...` falharem** no GitHub Actions (a compilação
> passa e só a publicação quebra), o `GITHUB_TOKEN` do fork está sem permissão de
> escrita — veja a seção *Problemas comuns no build* do
> [guia do cliente](https://github.com/comunitariogpt-blip/rustdesk/blob/master/docs/CUSTOM-SERVER-PTBR.md#9-problemas-comuns-no-build).

> Os ícones em `brand_suporte/` neste repositório são **genéricos de exemplo** —
> use-os como gabarito de tamanhos e substitua pelos da sua marca.

> **Teste rápido sem recompilar (Windows):** renomeie o instalador para
> `rustdesk-host=SEU_IP,key=SUA_CHAVE_PUBLICA.exe` — o RustDesk lê a
> configuração do nome do arquivo. Não funciona se a chave contiver `/`.

---

## 5. Segurança — o que NUNCA versionar

O [`.gitignore`](.gitignore) já bloqueia os itens sensíveis. **Nunca** commite:

| Item                         | Por quê                                                  |
|------------------------------|----------------------------------------------------------|
| `data/` (incl. `id_ed25519`) | Contém a **chave privada** do servidor — vazamento crítico |
| `panel/secrets.env`          | Usuário/senha do banco e `APP_KEY`                       |
| `*.pfx`                      | Certificados de assinatura de código                     |
| `github_ssh_key/`            | Chaves SSH de deploy                                     |
| `panel/public/dist/`         | Binários compilados (e marca real)                       |
| `brand/`, `logo1x1/`, `vertical.*` | Arte da marca específica do deploy                 |

Faça **backup** da pasta `./data` (sobretudo `id_ed25519`): se a chave privada for
perdida, **todos os clientes precisam ser reconfigurados**.

---

## 6. Apêndice: comandos rápidos e troubleshooting

```bash
# Backend
docker compose up -d / down / restart / ps
docker compose logs -f hbbs

# Banco
mysql -u rustdesk_panel -p rustdesk_panel < panel/schema.sql

# Painel
cp panel/secrets.env.example panel/secrets.env   # e edite
php panel/seed_admin.php admin                    # cria admin (senha aleatória)
```

**Problemas comuns**

- **404 em todas as rotas do painel** → `mod_rewrite` desabilitado ou
  `AllowOverride All` ausente no VirtualHost. Habilite o módulo e recarregue o Apache.
- **Erro "could not find driver" / falha de conexão ao banco** → falta a extensão
  `pdo_mysql` do PHP. Instale (`php-mysql` no Ubuntu) e reinicie o Apache.
- **Clientes não conectam** → portas `21115–21119` bloqueadas no firewall, ou o
  backend rodando em Docker Desktop (Win/macOS) com `network_mode: host`
  (use Linux em produção).
- **Painel sem a marca certa** → confira `PANEL_BRAND`/`PANEL_TITLE` no
  `secrets.env` e se o arquivo está legível pelo Apache.
