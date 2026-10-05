# REDCap REST

Luke Stevens, Murdoch Children's Research Institute https://www.mcri.edu.au

[https://github.com/lsgs/redcap-redcap-rest](https://github.com/lsgs/redcap-redcap-rest)

## Description

An external module enabling REDCap to send outbound API calls when saving data entry or survey forms and specified conditions are met. This can facilitate copying of data from your REDCap project to another application via its API, or to another REDCap project in either the same or a different instance of REDCap.

v1.4.0 introduces the facility for configuring sensitive configuration such as API or Autorization tokens at system level rather than hard-coding tokens into project module configuration. Use the placeholder `[token-ref:someref]` in place of your sensitive configuration value, and have your system administrator configure `someref` at system level. Tokens configured this way are also masked in logging.

## Limitations

* Initial implementation is of outbound API calls only.
* An implementation of authentication using OAuth2 is included but may not be suitable for all cases.

## Configuration in Projects

Multiple outbound API messages can be configured via the External Modules Configure dialog.

**Enabled?**
* Check to enable a message.

**Trigger form(s)**
* One or more instruments for which the current message will be triggered.

**Trigger condition**
* *Optional*: REDCap logic expression that must evaluate to *true* for the current record in order for the message to be generated. Leave empty to always send on saving the trigger form(s).
	
**Request URL**
* The URL of the endpoint the message will be sent *to*. Piping supported.
```
https://consentmgt.ourplace.org/api/record/[record_id]
```

**Payload**
* *Optional*: Textarea for specifying the form of the payload in JSON format. Piping supported, including of references to system-level token configuration. Wrap sections in `{{...}}` to skip piping replacement of variable names, e.g. when sending `filterLogic=` for a REDCap export records request.
```json
{
  "consent": [consent],
  "consent_date": "[consentdt]",
  "property_no_piping": "{{[consentdt]}}"
}
```

**Content Type**
* *Optional* Option to specify an alternative to 'application/json'.

**Additional Headers**
* *Optional* Additional headers along with Content-Type and Content-Length. Piping supported.

**cURL Options**
* *Optional* Key-value pairs for cURL settings, one pair per line in the notes box. Piping supported.

**OAuth2 type**
* *Optional* Select "Client Credentials" to utilise OAuth2 for the API connection.

**OAuth2 configuration settings**
* *Required (when OAuth2 type selected)* JSON-format string of configuration information for OAuth2 connection. Piping supported.

**Capture of Return Data**

API response data can be captured into fields within the same event as the triggering form.

**Result Field**
* *Optional* Select a field (e.g. a Notes-type field) in which to store the entire response (useful for debugging or for extracting values from complex reposnses using JavaScript).

**Response HTTP Code**
* *Optional* Record the HTTP code of the response to this field (useful for controlling behaviour based on success or otherwise of the API call).

**Map JSON Response Data to Fields**
* *Optional*, *Repeating* For JSON reponses, enter a property value to find in the response and a corresponding field name into which the property's value will be stored.

## Examples
### REDCap API
Call a REDCap API endpoint to obtain the value of field `[fieldtogetvaluefor]` for the record id piped in from field `[recordtofind]`:
* Request URL: `https://redcap.someplace.edu/api/`
* HTTP Method: `POST`
* Payload form: `token=[token-ref:my-project-123-token]&content=record&type=flat&format=json&records=[recordtofind]&fields[]=record_id&fields[]=fieldtogetvaluefor`
* Content Type: `application/x-www-form-urlencoded` (note *not* `application/json`)

### Australia/New Zealand Clinical Trial Registry (https://anzctr.org.au/)
Obtain published details of a clinical trial identified using its ANZCTR ID (piped into paylod using `[anzctrid]`): 
* Request URL: `https://www.anzctr.org.au/WebServices/AnzctrWebservices.asmx`
* HTTP Method: `POST`
* Payload form: 
```xml
<?xml version="1.0" encoding="utf-8"?>
<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
  <soap12:Body>
    <AnzctrTrialDetails xmlns="http://anzctr.org.au/WebServices/AnzctrWebServices">
      <ids>[anzctrid]</ids>
    </AnzctrTrialDetails>
  </soap12:Body>
</soap12:Envelope>
```
* Content Type: `application/soap+xml; charset=utf-8`

### Basic Authentication
Send a payload to an API endpoint secured with Basic Auth, uncluding an encoded token as an HTTP header:
* Request URL: `https://deep.thought.org/endpoint/`
* HTTP Method: `POST`
* Payload form: 
```json
{ "answer":42 }
```
* Content Type: `application/json`
* Additional headers: `Authorization: Basic SWYgdGhhdCdzIHRoZSBhbnN3ZXIsIHdoYXQgaXMgdGhlIHF1ZXN0aW9uPw==`
or, better:
* Additional headers: `Authorization: Basic [token-ref:my-basic-auth-token]`

### OAuth2 (Client Credentials)
Send a payload to an API protected by an OAuth2 client-credentials flow. The
module obtains a bearer token from the token endpoint and then calls the request
URL with `Authorization: Bearer <token>`.
* Request URL: `https://api.example.org/resource`
* HTTP Method: `POST`
* Payload form:
```json
{ "answer": 42 }
```
* Content Type: `application/json`
* OAuth2 type: `Client Credentials`
* OAuth2 configuration settings:
```json
{
  "auth-url": "https://api.example.org/oauth/token",
  "client-id": "[token-ref:my-client-id]",
  "client-secret": "[token-ref:my-client-secret]"
}
```
See [OAuth2 (Client Credentials) setup](#oauth2-client-credentials-setup) below
for the step-by-step configuration and common pitfalls.

## OAuth2 (Client Credentials) setup

Setting up an OAuth2 client-credentials call spans **two** configuration
surfaces: the system-level API Token Management (admin only) and the project
module configuration. Do the system part first, then the project part.

### Step 1 — System level: store the credentials

In **Control Center → External Modules → REDCap REST → System configuration →
API Token Management**, add one entry per secret (typically the client id and
the client secret), each using lookup option **"Use token as specified"**:

| Reference name | Request URL prefix (token scope) | Value |
|---|---|---|
| `my-client-id` | `https://api.example.org/oauth/token` | *your client id* |
| `my-client-secret` | `https://api.example.org/oauth/token` | *your client secret* |

* **Reference name** is the `xyz` you will reference as `[token-ref:xyz]` in the
  project. It must match exactly — a typo surfaces only later as a
  "Token ... not found" error.
* **Request URL prefix (token scope)** is a *prefix match*, not a full URL: the
  token is substituted only when the URL it is being used for **begins with**
  this value. For client-credentials, the client id and secret are sent to the
  **token endpoint**, so scope them to the `auth-url` — see the scope pitfall
  below.
* Paste values carefully — a stray leading/trailing character is invisible in the
  textarea and will cause an authentication failure that looks like a wrong
  secret.

### Step 2 — Project level: reference them

In the project's module **Configure** dialog, add a message with:
* **Request URL** — the protected resource endpoint, e.g.
  `https://api.example.org/resource`.
* **OAuth2 type** — `Client Credentials`.
* **OAuth2 configuration settings** — JSON referencing the system entries by
  name (never paste the real values here):
```json
{
  "auth-url": "https://api.example.org/oauth/token",
  "client-id": "[token-ref:my-client-id]",
  "client-secret": "[token-ref:my-client-secret]"
}
```
* **OAuth2 storage cache** — leave empty; the module writes the cached bearer
  token here and reuses it until shortly before expiry.

### Step 3 — Verify

Save the trigger form on a record, then check the module logs. The token exchange
and the resource call are both logged (token/secret values are masked). A
successful run shows the token endpoint returning 200 followed by the resource
call. If capturing the response, point a result field at a Notes field to see the
body come back.

### Common pitfalls

* **`auth-url` must be the full token endpoint path.** The module POSTs the
  token request to `auth-url` **verbatim** — it appends nothing. A bare host like
  `https://api.example.org` will fail; use the full path, e.g.
  `https://api.example.org/oauth/token`.
* **OAuth2 credentials are scoped against the `auth-url`, not the request URL.**
  The `[token-ref:...]` references in the OAuth2 configuration (the client id and
  secret) are substituted for the call to the **token endpoint**, so their scope
  prefix (Step 1) is compared against the `auth-url` — not the message's Request
  URL. Scope those entries to a prefix of the `auth-url`; the full token-endpoint
  path (e.g. `https://api.example.org/oauth/token`) is the tightest, safest
  choice. (Token-refs used elsewhere, such as in the payload or headers, are
  still scoped against the message's Request URL.)
* **`username`/`password` are not used by client-credentials.** Only `auth-url`,
  `client-id`, and `client-secret` are read from the OAuth2 configuration for the
  client-credentials grant. Any `username`/`password` keys are ignored.

## Configuration of Sensitive Parameters at System Level (From v1.4.0)

Configure sensitive configuration such as API or Autorization tokens at system level rather than hard-coding tokens into project module configuration. Use the placeholder `[token-ref:someref]` in place of your sensitive configuration value, and have your system administrator configure `someref` at system level. Tokens configured this way are also masked in logging.

**Reference name** (field label; internally the token reference)
* Unique reference or key for each token. Reference in project module settings in piping-style form as <code>[token-ref:xyz]</code> where <code>xyz</code> matches this reference.

**Request URL prefix (token scope)**
* The token is substituted only when the target URL **begins with** this value (a prefix match). Helps prevent exposing the token to an unintended URL. For OAuth2 client-credentials, the credentials go to the token endpoint, so scope their entries to a prefix of the `auth-url` (the full token-endpoint path is the tightest choice) — see the [OAuth2 setup pitfalls](#common-pitfalls).

**Token Lookup Option**
* Choose whether to specify the sensitive value or look up an API token for a project and user in the current instance of REDCap. 
 * Lookup: Read the token belonging to the specified user in the specified project.
 * Specify: Enter the sensitive value directly.

**Token Lookup Option "Lookup": Project**
* The project to read a REDCap API token from.

**Token Lookup Option "Lookup": Username**
* The user to read a REDCap API token from.

**Token Lookup Option Specify**
* The specific value to utilise where configured, e.g. a token for Basic Authentication with an external API.