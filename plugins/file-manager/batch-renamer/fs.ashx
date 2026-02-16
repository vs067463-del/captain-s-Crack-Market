<%@ WebHandler Language="C#" Class="BatchRenamerFsHandler" %>
using System;
using System.IO;
using System.Web;
using System.Text;
using System.Linq;
using System.Collections.Generic;
using System.Web.Script.Serialization;

public class BatchRenamerFsHandler : IHttpHandler
{
    // Базовый виртуальный каталог по умолчанию; можно переопределить параметром vdir=/ftp
    static readonly string DefaultVDir = "/ftp";

    public bool IsReusable { get { return false; } }

    public void ProcessRequest(HttpContext ctx)
    {
        ctx.Response.ContentType = "application/json; charset=utf-8";
        ctx.Response.ContentEncoding = Encoding.UTF8;

        try
        {
            string vdir = (ctx.Request["vdir"] ?? DefaultVDir).Trim();
            string rootPhysical = ResolveRootPhysical(ctx, vdir);
            if (string.IsNullOrEmpty(rootPhysical) || !Directory.Exists(rootPhysical))
                throw new Exception("Root not found for VDir " + vdir);

            string action = (ctx.Request["action"] ?? "").Trim().ToLowerInvariant();
            switch (action)
            {
                case "list":   HandleList(ctx, rootPhysical);   break;
                case "rename": HandleRename(ctx, rootPhysical); break;
                default: WriteOk(ctx, new { action = "ping", root = rootPhysical }); break;
            }
        }
        catch (Exception ex)
        {
            WriteError(ctx, ex.Message);
        }
    }

    void HandleList(HttpContext ctx, string rootPhysical)
    {
        string pathRaw = ctx.Request["path"] ?? "/";
        string rel = DecodeAndNormalizeRel(pathRaw);
        string phys = ToPhysical(rootPhysical, rel);
        if (!Directory.Exists(phys)) throw new Exception("Directory not found.");

        var entries = new List<Dictionary<string, object>>();
        foreach (var full in Directory.GetFileSystemEntries(phys))
        {
            bool isDir = Directory.Exists(full);
            string name = Path.GetFileName(full);
            string itemRel = CombineRel(rel, name + (isDir ? "/" : ""));
            long size = 0;
            try { if (!isDir) size = new FileInfo(full).Length; } catch { size = 0; }
            DateTime dt = (isDir ? Directory.GetLastWriteTime(full) : File.GetLastWriteTime(full));

            var dict = new Dictionary<string, object>();
            dict.Add("name", name + (isDir ? "/" : ""));
            dict.Add("dir", isDir);
            dict.Add("size", size);
            dict.Add("mtime", dt.ToString("yyyy-MM-dd HH:mm:ss"));
            dict.Add("path", itemRel);
            entries.Add(dict);
        }

        entries = entries.OrderBy(x => ((bool)x["dir"]) ? 0 : 1).ThenBy(x => (string)x["name"]).ToList();
        WriteOk(ctx, new { action = "list", path = rel, count = entries.Count, items = entries });
    }

    void HandleRename(HttpContext ctx, string rootPhysical)
    {
        string src = DecodeAndNormalizeRel(ctx.Request["src"] ?? "");
        string dst = DecodeAndNormalizeRel(ctx.Request["dst"] ?? "");
        if (src == "" || dst == "") throw new Exception("Bad paths.");

        string pSrc = ToPhysical(rootPhysical, src);
        string pDst = ToPhysical(rootPhysical, dst);

        bool srcDir = Directory.Exists(pSrc);
        bool srcFile = File.Exists(pSrc);
        if (!(srcDir || srcFile)) throw new Exception("Source not found.");
        if (Directory.Exists(pDst) || File.Exists(pDst)) throw new Exception("Destination exists.");

        string parentDst = Path.GetDirectoryName(pDst);
        if (!Directory.Exists(parentDst)) throw new Exception("Destination parent missing.");

        if (srcDir) Directory.Move(pSrc, pDst);
        else File.Move(pSrc, pDst);

        WriteOk(ctx, new { action = "rename", src = src, dst = dst });
    }

    /* Helpers */
    static string[] DecodeSegments(string rel)
    {
        return rel.Split(new[] { '/' }, StringSplitOptions.RemoveEmptyEntries)
                  .Select(seg => Uri.UnescapeDataString(seg))
                  .Where(seg => seg != "." && seg != "..")
                  .ToArray();
    }

    static string DecodeAndNormalizeRel(string rel)
    {
        rel = (rel ?? "").Replace("\\", "/").Trim();
        if (!rel.StartsWith("/")) rel = "/" + rel;
        rel = rel.Replace("//", "/");
        if (rel == "/") return "/";
        var parts = DecodeSegments(rel);
        string merged = "/" + string.Join("/", parts);
        if (rel.EndsWith("/")) merged += "/";
        return merged;
    }

    static string CombineRel(string parent, string child)
    {
        parent = DecodeAndNormalizeRel(parent);
        if (!parent.EndsWith("/")) parent += "/";
        child = DecodeAndNormalizeRel("/" + child.TrimStart('/')).TrimStart('/');
        return DecodeAndNormalizeRel(parent + child);
    }

    static string ToPhysical(string rootPhysical, string rel)
    {
        rel = DecodeAndNormalizeRel(rel);
        string sub = rel.TrimStart('/');
        string full = Path.GetFullPath(Path.Combine(rootPhysical, sub));
        if (!full.StartsWith(rootPhysical, StringComparison.OrdinalIgnoreCase))
            throw new Exception("Out of root.");
        return full;
    }

    static string ResolveRootPhysical(HttpContext ctx, string vdir)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(vdir)) vdir = DefaultVDir;
            if (!vdir.StartsWith("/")) vdir = "/" + vdir.Trim();
            string p = ctx.Server.MapPath(vdir);
            if (!string.IsNullOrEmpty(p) && Directory.Exists(p)) return p;

            p = ctx.Server.MapPath("~" + vdir);
            if (!string.IsNullOrEmpty(p) && Directory.Exists(p)) return p;

            p = Path.Combine(ctx.Server.MapPath("~"), vdir.Trim('/', '\\'));
            if (!string.IsNullOrEmpty(p) && Directory.Exists(p)) return p;
        } catch { }
        return null;
    }

    /* JSON helpers */
    static void WriteOk(HttpContext ctx, object obj)
    {
        var wrap = new Dictionary<string, object>();
        wrap["ok"] = true;
        var props = obj.GetType().GetProperties();
        foreach (var p in props) wrap[p.Name] = p.GetValue(obj, null);

        var ser = new JavaScriptSerializer(); ser.MaxJsonLength = Int32.MaxValue;
        ctx.Response.StatusCode = 200;
        ctx.Response.Write(ser.Serialize(wrap));
    }

    static void WriteError(HttpContext ctx, string msg)
    {
        var ser = new JavaScriptSerializer(); ser.MaxJsonLength = Int32.MaxValue;
        ctx.Response.StatusCode = 500;
        ctx.Response.Write(ser.Serialize(new { ok = false, error = msg }));
    }
}